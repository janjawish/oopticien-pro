-- V7 : statut explicite des lignes grises du suivi client.
ALTER TABLE dossiers
  MODIFY COLUMN folder_status ENUM(
    'brouillon','devis','en_cours','demande_mutuelle','pec_acceptee','a_facturer',
    'facture','teletransmis','commande_a_faire','commande_envoyee','verres_recus',
    'montage','pret','client_prevenu','remis','cloture','bloque','annule'
  ) NOT NULL DEFAULT 'brouillon';
