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

$string['close'] = 'Chiudi';
$string['dixeo_imageeditor:edit'] = 'Modificare immagini di contenuto incorporate con IA';
$string['editimage'] = 'Modifica immagine';
$string['error_delete_blocked'] = 'Impossibile eliminare dalla cronologia mentre un job è in corso.';
$string['error_delete_current'] = 'Impossibile eliminare la voce della cronologia corrispondente all\'immagine corrente.';
$string['error_job_failed'] = 'Generazione immagine non riuscita. Riprova.';
$string['error_locked'] = 'È già in corso un job immagine per questa immagine.';
$string['error_manual_blocked'] = 'Impossibile salvare modifiche manuali mentre un job immagine IA è in corso.';
$string['error_manual_invalid_image'] = 'L\'immagine modificata non può essere elaborata.';
$string['error_not_eligible'] = 'Questa immagine non può essere modificata.';
$string['error_revert_blocked'] = 'Impossibile ripristinare mentre un job è in corso.';
$string['error_upload_blocked'] = 'Impossibile caricare mentre un job immagine IA è in corso.';
$string['error_upload_invalid_image'] = 'Il file caricato non è un\'immagine supportata.';
$string['error_upload_too_large'] = 'L\'immagine supera la dimensione massima di caricamento ({$a}).';
$string['eventcontentimagejobstarted'] = 'Processo IA immagine di contenuto avviato';
$string['eventcontentimagejobstarteddesc'] = 'L\'utente con id \'{$a->userid}\' ha avviato un processo {$a->mode} ({$a->jobid}) per un\'immagine di contenuto nel corso {$a->courseid}.';
$string['eventcontentimageupdated'] = 'Immagine di contenuto aggiornata';
$string['eventcontentimageupdateddesc'] = 'L\'utente con id \'{$a->userid}\' ha aggiornato l\'immagine incorporata \'{$a->filename}\' nel corso {$a->courseid} (origine: {$a->source}).';
$string['eventcontentimageversiondeleted'] = 'Versione immagine di contenuto eliminata';
$string['eventcontentimageversiondeleteddesc'] = 'L\'utente con id \'{$a->userid}\' ha eliminato la versione {$a->versionid} dalla cronologia nel corso {$a->courseid}.';
$string['filtername'] = 'Editor Immagini Dixeo';
$string['generating_status'] = 'Generazione immagine...';
$string['history_actions'] = 'Azioni versione';
$string['history_delete'] = 'Elimina dalla cronologia';
$string['history_label'] = 'Cronologia versioni';
$string['history_preview_next'] = 'Versione successiva';
$string['history_preview_prev'] = 'Versione precedente';
$string['history_preview_title'] = 'Anteprima versione';
$string['history_set_current'] = 'Imposta come immagine corrente';
$string['image_updated'] = 'Immagine aggiornata';
$string['instructions_required'] = 'Descrivi le modifiche da applicare.';
$string['manual_apply_crop'] = 'Ritaglia';
$string['manual_brightness'] = 'Luminosità';
$string['manual_contrast'] = 'Contrasto';
$string['manual_discard'] = 'Annulla';
$string['manual_download'] = 'Scarica';
$string['manual_edit_start'] = 'Modifica immagine manualmente';
$string['manual_filter_grayscale'] = 'Bianco e nero';
$string['manual_filter_sepia'] = 'Seppia';
$string['manual_flip_horizontal'] = 'Capovolgi orizzontalmente';
$string['manual_flip_vertical'] = 'Capovolgi verticalmente';
$string['manual_redo'] = 'Ripeti';
$string['manual_rotate_clockwise'] = 'Ruota';
$string['manual_save'] = 'Salva';
$string['manual_toolbar_adjust'] = 'Regola immagine';
$string['manual_toolbar_history'] = 'Cronologia ed esportazione';
$string['manual_toolbar_zoom'] = 'Zoom';
$string['manual_undo'] = 'Annulla';
$string['manual_unsaved_changes_body'] = 'Hai modifiche manuali non salvate. Se esci ora, andranno perse.';
$string['manual_unsaved_changes_title'] = 'Annullare le modifiche non salvate?';
$string['manual_zoom_in'] = 'Ingrandisci';
$string['manual_zoom_out'] = 'Riduci';
$string['manual_zoom_reset'] = 'Reimposta zoom';
$string['modal_title'] = 'Editor Immagini Dixeo';
$string['mode_edit_current_image'] = 'Modifica attuale';
$string['mode_new_image'] = 'Nuova immagine';
$string['pluginname'] = 'Editor Immagini Dixeo';
$string['privacy:metadata'] = 'Il filtro Editor Immagini Dixeo memorizza metadati della cronologia versioni e byte di immagini archiviate per immagini di contenuto incorporate.';
$string['privacy:metadata:filename'] = 'Nome file dell\'immagine incorporata.';
$string['privacy:metadata:historyfiles'] = 'Le copie archiviate delle versioni precedenti delle immagini sono memorizzate nel file system.';
$string['privacy:metadata:local_dixeo'] = 'Le azioni di generazione e modifica con IA trasferiscono prompt e dati immagine all\'API Dixeo tramite il plugin local_dixeo.';
$string['privacy:metadata:local_dixeo:images'] = 'Byte immagine di origine o generati inviati per l\'elaborazione IA.';
$string['privacy:metadata:local_dixeo:prompt'] = 'Prompt utente o istruzioni di modifica inviate per l\'elaborazione IA.';
$string['privacy:metadata:source'] = 'Come è stata creata la versione archiviata.';
$string['privacy:metadata:timecreated'] = 'Quando è stata creata la voce della cronologia versioni.';
$string['privacy:metadata:usermodified'] = 'L\'utente che ha creato una voce della cronologia versioni.';
$string['privacy:metadata:versiontable'] = 'Memorizza metadati sulle versioni archiviate delle immagini per contenuto incorporato.';
$string['privacy:pathversions'] = 'Cronologia versioni immagine';
$string['prompt_label_edit'] = 'Descrivi le modifiche da apportare all\'immagine';
$string['prompt_label_generate'] = 'Descrivi l\'immagine che vuoi che l\'IA crei';
$string['prompt_placeholder_edit'] = 'es. Rimuovi il portatile dalla scrivania, ingrandisci leggermente e mantieni la stessa illuminazione.';
$string['prompt_placeholder_generate'] = 'Prova \'Paesaggio montano\'';
$string['prompt_required'] = 'Inserisci una descrizione prima di continuare.';
$string['quality_high'] = 'Alta';
$string['quality_label'] = 'Qualità';
$string['quality_low'] = 'Bassa';
$string['quality_medium'] = 'Media';
$string['shape_label'] = 'Formato immagine';
$string['shape_landscape'] = 'Orizzontale';
$string['shape_portrait'] = 'Verticale';
$string['shape_square'] = 'Quadrato';
$string['submit_edit'] = 'Modifica';
$string['submit_generate'] = 'Genera';
$string['task_cleanup_version_history'] = 'Pulizia della cronologia delle versioni delle immagini Dixeo';
$string['upload_image'] = 'Carica';
$string['upload_invalid_type'] = 'Scegli un file immagine supportato ({$a}).';
$string['upload_replace_body'] = 'Il file selezionato sostituirà l\'immagine corrente. La versione precedente verrà salvata nella cronologia versioni.';
$string['upload_replace_confirm'] = 'Sostituisci immagine';
$string['upload_replace_title'] = 'Sostituire l\'immagine corrente?';
