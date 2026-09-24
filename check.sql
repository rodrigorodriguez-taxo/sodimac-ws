-- Check samples linked to agenda 2606
SELECT am.id_agenda_muestra, am.id_muestra
FROM sod_inv_agenda_muestra am
WHERE am.id_agenda = 2606
  AND am.fl_activo = 'S';
