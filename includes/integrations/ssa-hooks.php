<?php

use RDT\CentrosEstetica\Domain\JornadaEstado;
use RDT\CentrosEstetica\Notifications\SsaBookingMail;
use RDT\CentrosEstetica\Notifications\TurnoNotification;
use RDT\CentrosEstetica\Repositories\JornadaCentroRepository;
use RDT\CentrosEstetica\Repositories\TurnoClienteRepository;
use RDT\CentrosEstetica\Services\JornadaCreator;

if (!defined('ABSPATH')) {
    exit;
}
/*
************************************************************************
    @param int $appointment_id
    @param array $data
    Función para creación de jornada al reservar una cita en SSA
    Cuando el plugin SSA agenda una cita, se crea un post Type "jornada_centro"
    Y se asocia al centro estético (usuario) que creó la cita.
    
***********************************************************************
*/
add_action('ssa/appointment/booked', function ($appointment_id, $data) {

    error_log('ssa-hooks::appointment booked - INICIO - appointment_id: ' . $appointment_id . ' data: (datos sensibles ocultos por seguridad)');

    if (!is_array($data)) {
        error_log('ssa-hooks::appointment booked - data no es array, saliendo');
        return;
    }

    // Appointment type: jornada completa
    $JORNADA_APPOINTMENT_TYPE_ID = 1;

    if (
        empty($data['appointment_type_id']) ||
        (int) $data['appointment_type_id'] !== $JORNADA_APPOINTMENT_TYPE_ID
    ) {
        error_log('ssa-hooks::appointment booked - appointment_type_id no es 1, es: ' . ($data['appointment_type_id'] ?? 'vacio'));
        return;
    }

    if (empty($data['start_date']) || empty($data['end_date'])) {
        error_log('ssa-hooks::appointment booked - faltan start_date o end_date');
        return;
    }

    // Las fechas de SSA vienen en UTC. Las pasamos a UTC-3.
    $start_datetime = new DateTime($data['start_date']);
    $start_datetime->modify('-3 hours');
    $end_datetime = new DateTime($data['end_date']);
    $end_datetime->modify('-3 hours');

    // Fecha y Hora de jornada
    $fecha       = $start_datetime->format('Y-m-d');
    $hora_inicio = $start_datetime->format('H:i');
    $hora_fin    = $end_datetime->format('H:i');
    
    // Centro: Priorizamos el customer_id que viene en los datos de la cita.
    // get_current_user_id() puede devolver el ID del administrador (ej. 44) si es él quien crea la cita manualmente.
    if (!empty($data['customer_id'])) {
        $wp_user_id = (int) $data['customer_id'];   //ID de centro estético que hizo la reserva en SSA
        error_log('ssa-hooks::appointment booked - Usando customer_id: ' . $wp_user_id);
    } else {
        $wp_user_id = get_current_user_id();    //ID del usuario WordPress actualmente logueado (podría ser el centro o un admin)   
        error_log('ssa-hooks::appointment booked - Usando get_current_user_id: ' . $wp_user_id);
    }

    if (!$wp_user_id || $wp_user_id == 0) {
        error_log('ssa-hooks::no hay usuario logueado (centro)');
        return;
    }

    $is_user_admin = false;

    // Si el usuario actual es admin (está creando en nombre de un centro), intentamos resolver el centro por email.
    // En cualquier caso, el admin debe poder leer el transient desde su propia sesión.
    if (current_user_can('manage_options')) {
        $is_user_admin = true;

        if (!empty($data['email'])) {
            $user_by_email = get_user_by('email', $data['email']);
            if ($user_by_email && is_a($user_by_email, 'WP_User')) {
                $roles = (array) $user_by_email->roles;
                $roles_permitidos = ['centro_estetico', 'centro_estetico_premium'];

                if (!empty(array_intersect($roles_permitidos, $roles))) {
                    $wp_user_id = $user_by_email->ID;
                    error_log('ssa-hooks::Admin creando jornada para centro ID ' . $wp_user_id . ' (email oculto por seguridad)');
                } else {
                    error_log('ssa-hooks::Admin creando, pero el usuario por email no es centro estético (email oculto por seguridad) roles: ' . implode(', ', $roles));
                }
            } else {
                error_log('ssa-hooks::Admin creando, pero no se encontró usuario por email (email oculto por seguridad)');
            }
        } else {
            error_log('ssa-hooks::Usuario admin creando jornada sin email en data');
        }
    }

    error_log('ssa-hooks::es_user_admin=' . ($is_user_admin ? 'true' : 'false') . ' wp_user_id=' . $wp_user_id . ' current_user_id=' . get_current_user_id());

    // Buscar el Post type 'centro_estetico' que tenga el campo 'usuario_responsable' igual al ID del usuario
    $centros = get_posts([
        'post_type'   => 'centro_estetico',
        'numberposts' => 1,
        'meta_key'    => 'usuario_responsable',
        'meta_value'  => $wp_user_id,
        'fields'      => 'ids',
    ]);

    if (empty($centros)) {
        error_log('ssa-hooks::Error: No se encontró un perfil de Centro Estético asociado al usuario ID ' . $wp_user_id);
        return;
    }

    $centro_id = $centros[0];
    error_log('ssa-hooks::Centro encontrado: ' . $centro_id . ' para user ID: ' . $wp_user_id);

    // Token público
    $token = wp_generate_uuid4();

    // Crear jornada mediante el servicio JornadaCreator.
    // El servicio centraliza la lógica de creación, validación de duplicados
    // y notificación por email. El hook solo aporta el contexto de SSA.
    $creator    = new JornadaCreator();
    error_log('ssa-hooks::Creando jornada con params: user_id=' . $wp_user_id . ' fecha=' . $fecha . ' inicio=' . $hora_inicio . ' fin=' . $hora_fin . ' ssa_id=' . $appointment_id);
    $jornada_id = $creator->crear(
        $wp_user_id,
        $fecha,
        $hora_inicio,
        $hora_fin,
        (int) $appointment_id, // ssa_reserva_id
        false                  // enviar_email: lo gestionamos después del popup de gel
    );

    if (is_wp_error($jornada_id)) {
        error_log('ssa-hooks::error creando jornada: ' . $jornada_id->get_error_message());
        return;
    }

    error_log('ssa-hooks::Jornada creada exitosamente con ID: ' . $jornada_id);

    /* Recuperar el token generado por el servicio para el transient

    */
    $token = get_post_meta($jornada_id, 'token_publico', true);

    // Guardamos el jornada_id en un transient de muy corta duración (60 seg).
    // El JS lo leerá via el endpoint rdt/v1/jornada-id-pendiente para poder
    // asociar la respuesta del popup de oferta a la jornada recién creada.
    
    $array_mail = [
        'jornada_id'  => $jornada_id,
        'wp_user_id'  => $wp_user_id,   // ID del usuario WordPress del centro (no del CPT)
        'centro_id'   => $centro_id,    // ID del CPT centro_estetico
        'fecha'       => $fecha,
        'hora_inicio' => $hora_inicio,
        'hora_fin'    => $hora_fin,
        'token'       => $token,
    ];

    //set_transient('rdt_jornada_pendiente_' . $wp_user_id, $jornada_id, 60);
    set_transient('rdt_jornada_pendiente_' . $wp_user_id, $array_mail, 60);

    // Si hay un usuario admin activo diferente al centro (flujo [admin_crear_jornada]),
    // guardamos el transient también con el ID del admin para que el JS lo encuentre.
    $admin_id = get_current_user_id();
    if ($admin_id && $admin_id !== $wp_user_id && current_user_can('manage_options')) {
        set_transient('rdt_jornada_pendiente_' . $admin_id, $array_mail, 60);
        error_log('ssa-hooks::Transient guardado para admin ID: ' . $admin_id . ' jornada_id: ' . $array_mail['jornada_id']);
    }

    error_log('ssa-hooks::jornada creada ID=' . $jornada_id . ' centro ID=' . $centro_id .
         ' fecha=' . $fecha . ' SSA ID=' . $appointment_id);

    // Notificar al centro con el enlace de la agenda para compartir con sus clientes.
    // Activamos un flag $ssa_mail_suprimido antes de enviar nuestro mail para que el filter de wp_mail
    // cancele el mail de SSA cuando llegue, evitando que el centro reciba dos mails.
    add_filter('wp_mail', function (array $args) use (&$ssa_mail_suprimido): array {
        if (!$ssa_mail_suprimido && str_contains($args['subject'] ?? '', 'Detalles de su reserva en RD-Tecno Belleza')) {
            $ssa_mail_suprimido = true;
            $args['to'] = ''; // Destinatario vacío: wp_mail lo descarta silenciosamente
            error_log('ssa-hooks::mail de SSA suprimido para jornada de centro registrado.');
        }
        return $args;
    }, 99);

    $ssa_mail_suprimido = false;    // Flag para suprimir el mail de SSA
    // El email de confirmación se envía desde JornadaGelController::handleConfirmar()
    // una vez que el usuario decidió sobre el pote de gel en el popup del frontend.
    // Eso aplica tanto para el flujo del centro como para el flujo admin.

}, 10, 2);  // Fin hook 'booked' SSA reserva jornada


/*
************************************************************************
    @param int $turno_id
    Hook interno disparado cuando un cliente cancela su propio turno.
    Envía una notificación de confirmación de cancelación al cliente.
    NO cancela la jornada del centro (ese es el comportamiento de ssa/appointment/canceled).
***********************************************************************
*/
add_action('rdt/turno/cancelado_por_cliente', function (int $turno_id) {

    error_log('ssa-hooks::rdt/turno/cancelado_por_cliente - Turno ID: ' . $turno_id);

    $email_cliente      = get_post_meta($turno_id, 'email_cliente', true);
    $nombre_cliente     = get_post_meta($turno_id, 'nombre_cliente', true);
    $servicio_id       = (int) get_post_meta($turno_id, 'servicio_id', true);
    $hora_inicio        = get_post_meta($turno_id, 'hora_inicio', true);
    $fecha              = get_post_meta($turno_id, 'fecha', true);
    $nombre_servicio    = get_the_title($servicio_id);

    // Obtener el nombre del centro estético asociado al turno
    $centro_id_raw = function_exists('get_field')
        ? get_field('centro_estetico_id', $turno_id)
        : get_post_meta($turno_id, 'centro_estetico_id', true);

    if (is_object($centro_id_raw) && isset($centro_id_raw->ID)) {
        $centro_id = $centro_id_raw->ID;
    } else {
        $centro_id = (int) $centro_id_raw;
    }

    $nombre_centro = $centro_id ? get_the_title($centro_id) : 'el centro estético';

    if (!$email_cliente) {
        error_log('ssa-hooks::rdt/turno/cancelado_por_cliente - No se encontró email del cliente para turno ID: ' . $turno_id);
        return;
    }

    $notification = new TurnoNotification();

    // Notificar al cliente que su cancelación fue procesada exitosamente
    $notification->notificarCancelacionPorCliente(
        $email_cliente,
        $nombre_cliente,
        $nombre_servicio,
        $nombre_centro,
        $fecha,
        $hora_inicio
    );

    // Notificar al centro que un cliente canceló su turno
    $notification->notificarCentroCancelacionPorCliente(
        $centro_id,
        $nombre_cliente,
        $nombre_servicio,
        $fecha,
        $hora_inicio
    );

}, 10, 1);


/*
************************************************************************
    @param int $appointment_id
    Función para cancelar jornada al cancelar una cita en SSA
***********************************************************************
*/
add_action('ssa/appointment/canceled', function ($appointment_id, $data) {

    error_log('ssa-hooks::appointment cancelled - SSA ID: ' . $appointment_id);

    if (!$appointment_id) {
        return;
    }

    // 1. Buscar la jornada asociada a esta reserva SSA
    $jornadaRepo = new JornadaCentroRepository();
    $jornada     = $jornadaRepo->findBySsaReservaId((int) $appointment_id);

    if (!$jornada) {
        error_log('ssa-hooks::appointment cancelled - No se encontró jornada para SSA ID ' . $appointment_id);
        return;
    }

    $centro_id = $jornada->centro_estetico_id;
    $fecha     = $jornada->fecha;

    // 2. Cancelar todos los turnos aprobados de clientes para esa jornada y notificarlos
    $turnoRepo     = new TurnoClienteRepository();
    $notification  = new TurnoNotification();
    $nombre_centro = get_the_title($centro_id);
    $turnos        = $turnoRepo->findAprobadosByCentroYFecha($centro_id, $fecha);

    foreach ($turnos as $turno) {
        $turnoRepo->actualizarEstado($turno->ID, \RDT\CentrosEstetica\Domain\TurnoEstado::CANCELADO);

        $email_cliente      = get_post_meta($turno->ID, 'email_cliente', true);
        $nombre_cliente     = get_post_meta($turno->ID, 'nombre_cliente', true);
        $servicio_id        = (int) get_post_meta($turno->ID, 'servicio_id', true);
        $hora_inicio_turno  = get_post_meta($turno->ID, 'hora_inicio', true);
        $nombre_servicio    = get_the_title($servicio_id);

        $notification->notificarCancelacionPorReagenda(
            $email_cliente,
            $nombre_cliente,
            $nombre_servicio,
            $nombre_centro,
            $fecha,
            $hora_inicio_turno
        );

        error_log('ssa-hooks::appointment cancelled - Turno ID ' . $turno->ID . ' cancelado y cliente notificado.');
    }

    // 3. Marcar la jornada como cancelada
    update_post_meta($jornada->id, 'jornada_estado', JornadaEstado::CANCELADA);

    error_log('ssa-hooks::appointment cancelled - Jornada ID ' . $jornada->id . ' cancelada. Turnos cancelados: ' . count($turnos));

}, 10, 2);


/*
************************************************************************
    @param int $appointment_id
    @param array $data
    Función para reagendar una jornada en SSA.
    Cuando el centro edita su jornada (cambiando la fecha o cualquier otro dato),
    este hook se dispara.

    - Si la fecha cambia (reagenda):
        1. Se actualiza la fecha y horario en nuestro CPT 'jornada_centro'.
        2. Se cancelan todos los turnos de clientes activos de la fecha anterior.
        3. Se notifica a esos clientes por email sobre la cancelación.
        4. Se suprime el email por defecto de SSA para que no se duplique la comunicación.
    - Si la fecha no cambia (otro tipo de edición):
        Simplemente se registra el evento, sin tomar acciones adicionales por ahora.
************************************************************************
*/
add_action('ssa/appointment/edited', function ($appointment_id, $new_data, $old_data) {

    error_log('ssa-hooks::edited data: (datos sensibles ocultos por seguridad)');

    // Comparamos si la fecha de inicio ha cambiado para detectar una reagenda.
    $old_start_date = new DateTime($old_data['start_date']);
    $new_start_date = new DateTime($new_data['start_date']);

    if ($old_start_date == $new_start_date) {
        error_log('ssa-hooks::edited - La jornada SSA ID ' . $appointment_id . ' fue editada, pero la fecha no cambió. No se tomaron acciones.');
        return;
    }

    // --- A partir de aquí, es una REAGENDA ---
    error_log('ssa-hooks::edited - REAGENDA DETECTADA para SSA ID ' . $appointment_id);

    $jornadaRepo = new JornadaCentroRepository();

    // 1. Buscar la jornada existente por su ID de reserva SSA
    $jornada = $jornadaRepo->findBySsaReservaId((int) $appointment_id);

    if (!$jornada) {
        error_log('ssa-hooks::edited (reagenda) - No se encontró jornada para SSA ID ' . $appointment_id);
        return;
    }

    $fecha_anterior  = $jornada->fecha;
    $centro_id       = $jornada->centro_estetico_id;

    // 2. Calcular nueva fecha y horarios convirtiendo UTC → UTC-3
    $start_datetime = new DateTime($new_data['start_date']);
    $start_datetime->modify('-3 hours');
    $end_datetime = new DateTime($new_data['end_date']);
    $end_datetime->modify('-3 hours');

    $fecha_nueva  = $start_datetime->format('Y-m-d');
    $hora_inicio  = $start_datetime->format('H:i');
    $hora_fin     = $end_datetime->format('H:i');

    // 3. Suprimir el email por defecto de SSA para que no se duplique la comunicación.
    // El filtro se añade justo antes de que se ejecute la lógica que podría disparar el mail.
    add_filter('wp_mail', function (array $args) use (&$ssa_mail_suprimido): array {
        // DEBUG: Logueamos todos los asuntos de correo que pasan por aquí durante la reagenda.
        error_log('ssa-hooks::wp_mail_filter - Asunto detectado: ' . ($args['subject'] ?? 'SIN ASUNTO'));

        // El asunto del mail de reagenda de SSA suele contener "modificada" o "actualizada".
        if (!$ssa_mail_suprimido && str_contains($args['subject'] ?? '', 'Su reserva ha sido modificada')) {
            $ssa_mail_suprimido = true;
            $args['to'] = ''; // Anulamos el destinatario para que wp_mail() descarte el correo.
            error_log('ssa-hooks::edited - Mail de reagenda de SSA suprimido.');
        }
        return $args;
    }, 99);
    $ssa_mail_suprimido = false;

    // 4. Cancelar todos los turnos activos de la fecha anterior y notificar clientes
    $turnoRepo    = new TurnoClienteRepository();
    $notification = new TurnoNotification();
    $nombre_centro = get_the_title($centro_id);
    $turnos       = $turnoRepo->findAprobadosByCentroYFecha($centro_id, $fecha_anterior);

    foreach ($turnos as $turno) {
        $turnoRepo->actualizarEstado($turno->ID, \RDT\CentrosEstetica\Domain\TurnoEstado::CANCELADO);

        $email_cliente      = get_post_meta($turno->ID, 'email_cliente', true);
        $nombre_cliente     = get_post_meta($turno->ID, 'nombre_cliente', true);
        $servicio_id        = (int) get_post_meta($turno->ID, 'servicio_id', true);
        $hora_inicio_turno  = get_post_meta($turno->ID, 'hora_inicio', true);
        $nombre_servicio    = get_the_title($servicio_id);

        $notification->notificarCancelacionPorReagenda(
            $email_cliente,
            $nombre_cliente,
            $nombre_servicio,
            $nombre_centro,
            $fecha_anterior,
            $hora_inicio_turno
        );

        error_log('ssa-hooks::edited (reagenda) - Turno ID ' . $turno->ID . ' cancelado y cliente notificado.');
    }

    // 5. Actualizar la jornada con la nueva fecha y horario
    $jornadaRepo->actualizarFechaYHorario($jornada->id, $fecha_nueva, $hora_inicio, $hora_fin);

    // 6. Enviar nuestro propio email de reagenda al centro estético.
    // Obtenemos el ID de usuario del centro para enviarle el correo.
    $wp_user_id = (int) get_post_meta($centro_id, 'usuario_responsable', true);
    if ($wp_user_id) {
        (new SsaBookingMail())->enviar($wp_user_id, $fecha_nueva, $hora_inicio, $hora_fin, $jornada->token_publico, true);
    }

    error_log('ssa-hooks::edited (reagenda) - Jornada ID ' . $jornada->id .
        ' reagendada de ' . $fecha_anterior . ' a ' . $fecha_nueva .
        '. Turnos cancelados: ' . count($turnos));

}, 10, 3);