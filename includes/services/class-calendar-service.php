<?php

declare(strict_types=1);

namespace RDT\CentrosEstetica\Services;

use DateTime;
use DateTimeZone;

/**
 * CalendarService
 * 
 * Se encarga de generar enlaces y archivos para calendarios externos (Google, Apple, Outlook).
 */
class CalendarService
{
    /**
     * Genera el enlace para agregar el evento a Google Calendar.
     */
    public function getGoogleCalendarUrl(string $titulo, string $descripcion, string $ubicacion, string $fecha, string $hora_inicio, string $hora_fin): string
    {
        [$start_utc, $end_utc] = $this->calcularFechasUtc($fecha, $hora_inicio, $hora_fin);

        $dates = $start_utc->format('Ymd\THis\Z') . '/' . $end_utc->format('Ymd\THis\Z');

        return "https://www.google.com/calendar/render?action=TEMPLATE&text=" . urlencode($titulo) .
               "&dates={$dates}&details=" . urlencode($descripcion) .
               "&location=" . urlencode($ubicacion);
    }

    /**
     * Genera el contenido de texto para un archivo .ics (iCalendar) compatible con iOS y Outlook.
     */
    public function generateIcsContent(string $uid, string $titulo, string $descripcion, string $ubicacion, string $fecha, string $hora_inicio, string $hora_fin): string
    {
        [$start_utc, $end_utc] = $this->calcularFechasUtc($fecha, $hora_inicio, $hora_fin);
        
        $now_utc = new DateTime('now', new DateTimeZone('UTC'));
        $dtstamp = $now_utc->format('Ymd\THis\Z');
        $dtstart = $start_utc->format('Ymd\THis\Z');
        $dtend   = $end_utc->format('Ymd\THis\Z');

        // Escapar caracteres especiales para formato ICS
        $titulo      = $this->escaparIcs($titulo);
        $descripcion = $this->escaparIcs($descripcion);
        $ubicacion   = $this->escaparIcs($ubicacion);

        // Construcción del archivo ICS
        return "BEGIN:VCALENDAR\r\n" .
               "VERSION:2.0\r\n" .
               "PRODID:-//RDT Tecno Belleza//NONSGML v1.0//ES\r\n" .
               "CALSCALE:GREGORIAN\r\n" .
               "METHOD:PUBLISH\r\n" .
               "BEGIN:VEVENT\r\n" .
               "UID:{$uid}\r\n" .
               "DTSTAMP:{$dtstamp}\r\n" .
               "DTSTART:{$dtstart}\r\n" .
               "DTEND:{$dtend}\r\n" .
               "SUMMARY:{$titulo}\r\n" .
               "DESCRIPTION:{$descripcion}\r\n" .
               "LOCATION:{$ubicacion}\r\n" .
               "STATUS:CONFIRMED\r\n" .
               "END:VEVENT\r\n" .
               "END:VCALENDAR";
    }

    /**
     * Convierte fecha/hora local de WP a objetos DateTime en UTC.
     */
    private function calcularFechasUtc(string $fecha, string $hora_inicio, string $hora_fin): array
    {
        $wp_timezone = wp_timezone();

        try {
            $start = new DateTime("{$fecha} {$hora_inicio}", $wp_timezone);
            $end   = new DateTime("{$fecha} {$hora_fin}", $wp_timezone);
        } catch (\Exception $e) {
            // Fallback defensivo a UTC si falla el parseo
            $start = new DateTime("{$fecha} {$hora_inicio}", new DateTimeZone('UTC'));
            $end   = new DateTime("{$fecha} {$hora_fin}", new DateTimeZone('UTC'));
        }

        $start_utc = (clone $start)->setTimezone(new DateTimeZone('UTC'));
        $end_utc   = (clone $end)->setTimezone(new DateTimeZone('UTC'));

        return [$start_utc, $end_utc];
    }

    /**
     * Escapa caracteres especiales según RFC 5545.
     */
    private function escaparIcs(string $texto): string
    {
        $texto = str_replace(["\\", ";", ","], ["\\\\", "\;", "\,"], $texto);
        $texto = str_replace("\n", "\\n", $texto);
        return $texto;
    }
}