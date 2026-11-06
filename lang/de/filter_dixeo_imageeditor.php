<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Language strings.
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['close'] = 'Schließen';
$string['dixeo_imageeditor:edit'] = 'Eingebettete Inhaltsbilder mit KI bearbeiten';
$string['editimage'] = 'Bild bearbeiten';
$string['error_delete_blocked'] = 'Löschen aus der Historie ist nicht möglich, solange ein Auftrag läuft.';
$string['error_delete_current'] = 'Der Historieneintrag, der dem aktuellen Bild entspricht, kann nicht gelöscht werden.';
$string['error_job_failed'] = 'Bildgenerierung fehlgeschlagen. Bitte versuchen Sie es erneut.';
$string['error_locked'] = 'Für dieses Bild läuft bereits ein Bildauftrag.';
$string['error_manual_blocked'] = 'Manuelle Bearbeitungen können nicht gespeichert werden, solange ein KI-Bildauftrag läuft.';
$string['error_manual_invalid_image'] = 'Das bearbeitete Bild konnte nicht verarbeitet werden.';
$string['error_not_eligible'] = 'Dieses Bild kann nicht bearbeitet werden.';
$string['error_revert_blocked'] = 'Zurücksetzen ist nicht möglich, solange ein Auftrag läuft.';
$string['error_upload_blocked'] = 'Hochladen ist nicht möglich, solange ein KI-Bildauftrag läuft.';
$string['error_upload_invalid_image'] = 'Die hochgeladene Datei ist kein unterstütztes Bild.';
$string['error_upload_too_large'] = 'Das Bild ist größer als die maximale Upload-Größe ({$a}).';
$string['eventcontentimagejobstarted'] = 'KI-Auftrag für Inhaltsbild gestartet';
$string['eventcontentimagejobstarteddesc'] = 'Der Benutzer mit der ID \'{$a->userid}\' hat einen {$a->mode}-Auftrag ({$a->jobid}) für ein Inhaltsbild im Kurs {$a->courseid} gestartet.';
$string['eventcontentimageupdated'] = 'Inhaltsbild aktualisiert';
$string['eventcontentimageupdateddesc'] = 'Der Benutzer mit der ID \'{$a->userid}\' hat das eingebettete Bild \'{$a->filename}\' im Kurs {$a->courseid} aktualisiert (Quelle: {$a->source}).';
$string['eventcontentimageversiondeleted'] = 'Inhaltsbildversion gelöscht';
$string['eventcontentimageversiondeleteddesc'] = 'Der Benutzer mit der ID \'{$a->userid}\' hat Version {$a->versionid} aus dem Verlauf im Kurs {$a->courseid} gelöscht.';
$string['filtername'] = 'Dixeo-Bildeditor';
$string['generating_status'] = 'Bild wird generiert...';
$string['history_actions'] = 'Versionsaktionen';
$string['history_delete'] = 'Aus der Historie löschen';
$string['history_label'] = 'Versionshistorie';
$string['history_preview_next'] = 'Nächste Version';
$string['history_preview_prev'] = 'Vorherige Version';
$string['history_preview_title'] = 'Versionsvorschau';
$string['history_set_current'] = 'Als aktuelles Bild festlegen';
$string['image_updated'] = 'Bild aktualisiert';
$string['instructions_required'] = 'Bitte beschreiben Sie die anzuwendenden Änderungen.';
$string['manual_apply_crop'] = 'Zuschneiden';
$string['manual_brightness'] = 'Helligkeit';
$string['manual_contrast'] = 'Kontrast';
$string['manual_discard'] = 'Verwerfen';
$string['manual_download'] = 'Herunterladen';
$string['manual_edit_start'] = 'Bild manuell bearbeiten';
$string['manual_filter_grayscale'] = 'Schwarzweiß';
$string['manual_filter_sepia'] = 'Sepia';
$string['manual_flip_horizontal'] = 'Horizontal spiegeln';
$string['manual_flip_vertical'] = 'Vertikal spiegeln';
$string['manual_redo'] = 'Wiederholen';
$string['manual_rotate_clockwise'] = 'Drehen';
$string['manual_save'] = 'Speichern';
$string['manual_toolbar_adjust'] = 'Bild anpassen';
$string['manual_toolbar_history'] = 'Historie und Export';
$string['manual_toolbar_zoom'] = 'Zoom';
$string['manual_undo'] = 'Rückgängig';
$string['manual_unsaved_changes_body'] = 'Sie haben nicht gespeicherte manuelle Bearbeitungen. Wenn Sie jetzt gehen, gehen diese Änderungen verloren.';
$string['manual_unsaved_changes_title'] = 'Nicht gespeicherte Änderungen verwerfen?';
$string['manual_zoom_in'] = 'Vergrößern';
$string['manual_zoom_out'] = 'Verkleinern';
$string['manual_zoom_reset'] = 'Zoom zurücksetzen';
$string['modal_title'] = 'Dixeo-Bildeditor';
$string['mode_edit_current_image'] = 'Aktuelles bearbeiten';
$string['mode_new_image'] = 'Neues Bild';
$string['pluginname'] = 'Dixeo-Bildeditor';
$string['privacy:metadata'] = 'Der Dixeo-Bildeditor-Filter speichert Metadaten zur Versionshistorie und archivierte Bildbytes für eingebettete Inhaltsbilder.';
$string['privacy:metadata:filename'] = 'Dateiname des eingebetteten Bildes.';
$string['privacy:metadata:historyfiles'] = 'Archivierte Kopien früherer Bildversionen werden im Dateisystem gespeichert.';
$string['privacy:metadata:local_dixeo'] = 'KI-Generierungs- und Bearbeitungsaktionen übertragen Eingabeaufforderungen und Bilddaten über das Plugin local_dixeo an die Dixeo-API.';
$string['privacy:metadata:local_dixeo:images'] = 'Quell- oder generierte Bildbytes für die KI-Verarbeitung.';
$string['privacy:metadata:local_dixeo:prompt'] = 'Benutzereingabe oder Bearbeitungsanweisungen für die KI-Verarbeitung.';
$string['privacy:metadata:source'] = 'Wie die archivierte Version erstellt wurde.';
$string['privacy:metadata:timecreated'] = 'Zeitpunkt der Erstellung des Versionshistorieneintrags.';
$string['privacy:metadata:usermodified'] = 'Der Benutzer, der einen Versionshistorieneintrag erstellt hat.';
$string['privacy:metadata:versiontable'] = 'Speichert Metadaten zu archivierten Bildversionen für eingebettete Inhalte.';
$string['privacy:pathversions'] = 'Bildversionshistorie';
$string['prompt_label_edit'] = 'Beschreiben Sie die am Bild vorzunehmenden Änderungen';
$string['prompt_label_generate'] = 'Beschreiben Sie das Bild, das die KI erstellen soll';
$string['prompt_placeholder_edit'] = 'z. B. Laptop vom Schreibtisch entfernen, leicht heranzoomen und dieselbe Beleuchtung beibehalten.';
$string['prompt_placeholder_generate'] = 'Versuchen Sie \'Gebirgslandschaft\'';
$string['prompt_required'] = 'Bitte geben Sie eine Beschreibung ein, bevor Sie fortfahren.';
$string['quality_high'] = 'Hoch';
$string['quality_label'] = 'Qualität';
$string['quality_low'] = 'Niedrig';
$string['quality_medium'] = 'Mittel';
$string['shape_label'] = 'Bildform';
$string['shape_landscape'] = 'Querformat';
$string['shape_portrait'] = 'Hochformat';
$string['shape_square'] = 'Quadrat';
$string['submit_edit'] = 'Bearbeiten';
$string['submit_generate'] = 'Generieren';
$string['task_cleanup_version_history'] = 'Dixeo-Bildversionshistorie bereinigen';
$string['upload_image'] = 'Hochladen';
$string['upload_invalid_type'] = 'Bitte wählen Sie eine unterstützte Bilddatei ({$a}).';
$string['upload_replace_body'] = 'Die ausgewählte Datei ersetzt das aktuelle Bild. Die vorherige Version wird in der Versionshistorie gespeichert.';
$string['upload_replace_confirm'] = 'Bild ersetzen';
$string['upload_replace_title'] = 'Aktuelles Bild ersetzen?';
