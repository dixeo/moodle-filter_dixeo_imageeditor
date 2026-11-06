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

$string['close'] = 'Fermer';
$string['dixeo_imageeditor:edit'] = 'Modifier des images de contenu intégrées avec l\'IA';
$string['editimage'] = 'Modifier l\'image';
$string['error_delete_blocked'] = 'Impossible de supprimer de l\'historique pendant qu\'une tâche est en cours.';
$string['error_delete_current'] = 'Impossible de supprimer l\'entrée de l\'historique correspondant à l\'image actuelle.';
$string['error_job_failed'] = 'Échec de la génération de l\'image. Veuillez réessayer.';
$string['error_locked'] = 'Une tâche d\'image est déjà en cours pour cette image.';
$string['error_manual_blocked'] = 'Impossible d\'enregistrer les modifications manuelles pendant qu\'une tâche d\'image IA est en cours.';
$string['error_manual_invalid_image'] = 'L\'image modifiée n\'a pas pu être traitée.';
$string['error_not_eligible'] = 'Cette image ne peut pas être modifiée.';
$string['error_revert_blocked'] = 'Impossible de revenir en arrière pendant qu\'une tâche est en cours.';
$string['error_upload_blocked'] = 'Impossible de téléverser pendant qu\'une tâche d\'image IA est en cours.';
$string['error_upload_invalid_image'] = 'Le fichier téléversé n\'est pas une image prise en charge.';
$string['error_upload_too_large'] = 'L\'image dépasse la taille maximale de téléversement ({$a}).';
$string['eventcontentimagejobstarted'] = 'Tâche IA d\'image de contenu démarrée';
$string['eventcontentimagejobstarteddesc'] = 'L\'utilisateur d\'id \'{$a->userid}\' a démarré une tâche {$a->mode} ({$a->jobid}) pour une image de contenu dans le cours {$a->courseid}.';
$string['eventcontentimageupdated'] = 'Image de contenu mise à jour';
$string['eventcontentimageupdateddesc'] = 'L\'utilisateur d\'id \'{$a->userid}\' a mis à jour l\'image intégrée \'{$a->filename}\' dans le cours {$a->courseid} (source : {$a->source}).';
$string['eventcontentimageversiondeleted'] = 'Version d\'image de contenu supprimée';
$string['eventcontentimageversiondeleteddesc'] = 'L\'utilisateur d\'id \'{$a->userid}\' a supprimé la version {$a->versionid} de l\'historique dans le cours {$a->courseid}.';
$string['filtername'] = 'Éditeur d\'Images Dixeo';
$string['generating_status'] = 'Génération de l\'image...';
$string['history_actions'] = 'Actions de version';
$string['history_delete'] = 'Supprimer de l\'historique';
$string['history_label'] = 'Historique des versions';
$string['history_preview_next'] = 'Version suivante';
$string['history_preview_prev'] = 'Version précédente';
$string['history_preview_title'] = 'Aperçu de la version';
$string['history_set_current'] = 'Définir comme image actuelle';
$string['image_updated'] = 'Image mise à jour';
$string['instructions_required'] = 'Veuillez décrire les modifications à appliquer.';
$string['manual_apply_crop'] = 'Recadrer';
$string['manual_brightness'] = 'Luminosité';
$string['manual_contrast'] = 'Contraste';
$string['manual_discard'] = 'Abandonner';
$string['manual_download'] = 'Télécharger';
$string['manual_edit_start'] = 'Modifier l\'image manuellement';
$string['manual_filter_grayscale'] = 'Noir et blanc';
$string['manual_filter_sepia'] = 'Sépia';
$string['manual_flip_horizontal'] = 'Retourner horizontalement';
$string['manual_flip_vertical'] = 'Retourner verticalement';
$string['manual_redo'] = 'Rétablir';
$string['manual_rotate_clockwise'] = 'Pivoter';
$string['manual_save'] = 'Enregistrer';
$string['manual_toolbar_adjust'] = 'Ajuster l\'image';
$string['manual_toolbar_history'] = 'Historique et export';
$string['manual_toolbar_zoom'] = 'Zoom';
$string['manual_undo'] = 'Annuler';
$string['manual_unsaved_changes_body'] = 'Vous avez des modifications manuelles non enregistrées. Si vous quittez maintenant, elles seront perdues.';
$string['manual_unsaved_changes_title'] = 'Abandonner les modifications non enregistrées ?';
$string['manual_zoom_in'] = 'Zoom avant';
$string['manual_zoom_out'] = 'Zoom arrière';
$string['manual_zoom_reset'] = 'Réinitialiser le zoom';
$string['modal_title'] = 'Éditeur d\'Images Dixeo';
$string['mode_edit_current_image'] = 'Modifier l\'actuelle';
$string['mode_new_image'] = 'Nouvelle image';
$string['pluginname'] = 'Éditeur d\'Images Dixeo';
$string['privacy:metadata'] = 'Le filtre Éditeur d\'Images Dixeo stocke les métadonnées de l\'historique des versions et les octets d\'images archivées pour les images de contenu intégrées.';
$string['privacy:metadata:filename'] = 'Nom du fichier de l\'image intégrée.';
$string['privacy:metadata:historyfiles'] = 'Les copies archivées des versions précédentes des images sont stockées dans le système de fichiers.';
$string['privacy:metadata:local_dixeo'] = 'Les actions de génération et d\'édition par IA transfèrent les invites et les données d\'image vers l\'API Dixeo via le plugin local_dixeo.';
$string['privacy:metadata:local_dixeo:images'] = 'Octets d\'image source ou générés envoyés pour le traitement IA.';
$string['privacy:metadata:local_dixeo:prompt'] = 'Invite utilisateur ou instructions d\'édition envoyées pour le traitement IA.';
$string['privacy:metadata:source'] = 'Comment la version archivée a été créée.';
$string['privacy:metadata:timecreated'] = 'Date de création de l\'entrée de l\'historique des versions.';
$string['privacy:metadata:usermodified'] = 'L\'utilisateur qui a créé une entrée de l\'historique des versions.';
$string['privacy:metadata:versiontable'] = 'Stocke les métadonnées des versions archivées d\'images pour le contenu intégré.';
$string['privacy:pathversions'] = 'Historique des versions d\'image';
$string['prompt_label_edit'] = 'Décrivez les modifications à apporter à l\'image';
$string['prompt_label_generate'] = 'Décrivez l\'image que vous souhaitez que l\'IA crée';
$string['prompt_placeholder_edit'] = 'p. ex. Retirer l\'ordinateur portable du bureau, zoomer légèrement et conserver le même éclairage.';
$string['prompt_placeholder_generate'] = 'Essayez « Paysage montagneux »';
$string['prompt_required'] = 'Veuillez saisir une description avant de continuer.';
$string['quality_high'] = 'Élevée';
$string['quality_label'] = 'Qualité';
$string['quality_low'] = 'Faible';
$string['quality_medium'] = 'Moyenne';
$string['shape_label'] = 'Format de l\'image';
$string['shape_landscape'] = 'Paysage';
$string['shape_portrait'] = 'Portrait';
$string['shape_square'] = 'Carré';
$string['submit_edit'] = 'Modifier';
$string['submit_generate'] = 'Générer';
$string['task_cleanup_version_history'] = 'Nettoyer l\'historique des versions d\'images Dixeo';
$string['upload_image'] = 'Téléverser';
$string['upload_invalid_type'] = 'Veuillez choisir un fichier image pris en charge ({$a}).';
$string['upload_replace_body'] = 'Le fichier sélectionné remplacera l\'image actuelle. La version précédente sera enregistrée dans l\'historique des versions.';
$string['upload_replace_confirm'] = 'Remplacer l\'image';
$string['upload_replace_title'] = 'Remplacer l\'image actuelle ?';
