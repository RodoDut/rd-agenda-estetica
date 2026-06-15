Esta es la manera en que responde el hook de reserva de turno de SSA, con la información del turno recién reservado. Es importante destacar que el campo "id" viene vacío, ya que el turno aún no ha sido creado en nuestra base de datos en este punto del proceso. Sin embargo, toda la información relevante del turno está presente, lo que nos permite utilizarla para crear el turno en nuestro sistema y luego actualizarlo con el ID generado.
ssa-hooks::appointment booked data: Array
(
    [id] => 
    [appointment_type_id] => 1
    [rescheduled_from_appointment_id] => 0
    [rescheduled_to_appointment_id] => 
    [group_id] => 0
    [author_id] => 0
    [customer_id] => 0
    [customer_information] => Array
        (
            [Name] => Centro Demo
            [Email] => siammashaka@gmail.com
            [Phone] => +54 341 579 5765
            [Address] => I Malvinas 190
            [City] => General Lagos
            [State] => Santa Fe
            [Notes] => 
        )

    [customer_timezone] => America/Buenos_Aires
    [customer_locale] => es_AR
    [start_date] => 2026-03-19 11:00:00
    [end_date] => 2026-03-19 23:00:00
    [title] => 
    [description] => 
    [payment_method] => 
    [payment_received] => 
    [mailchimp_list_id] => 
    [google_calendar_id] => 
    [google_calendar_event_id] => 
    [web_meeting_password] => 
    [web_meeting_id] => 
    [web_meeting_url] => 
    [allow_sms] => 
    [status] => booked
    [date_created] => 
    [date_modified] => 
    [expiration_date] => 
    [post_information] => Array
        (
            [booking_url] => https://rdtecnobelleza.net/reservas/
            [booking_post_id] => 291
            [booking_title] => Reservas
        )

    [fetch] => Array
        (
            [add_to_calendar_links] => 1
        )

    [mepr_membership] => Array
        (
        )

    [staff_ids] => Array
        (
        )

    [selected_resources] => Array
        (
        )

    [opt_in_notifications] => 
)
