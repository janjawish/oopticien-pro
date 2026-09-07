-- V10 : statut dédié aux dossiers nécessitant une dérogation.
ALTER TABLE dossiers
  MODIFY COLUMN folder_status ENUM(
    'brouillon','devis','en_cours','demande_mutuelle','pec_acceptee','a_facturer',
    'facture','teletransmis','commande_a_faire','commande_envoyee','verres_recus',
    'montage','pret','client_prevenu','remis','cloture','bloque','sav','derogation','annule'
  ) NOT NULL DEFAULT 'brouillon';
