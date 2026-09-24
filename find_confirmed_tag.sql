-- Ver TAGs y capturas en agenda 2606
SELECT 
    t.id_tag,
    t.numero_tag,
    t.estado_tag,
    COUNT(d1.id_conteo_det) as total_c1
FROM sod_inv_tag t
LEFT JOIN sod_inv_conteo_det d1 ON d1.id_tag = t.id_tag AND d1.estado_registro = 'VIGENTE'
WHERE t.id_agenda = 2606 AND t.fl_activo = 'S'
GROUP BY t.id_tag, t.numero_tag, t.estado_tag;
