-- Pratyangi Fashions ERP V1.5 - Godown location: separate Shelf number.
-- BACK UP FIRST: phpMyAdmin > your database > Export > Quick > SQL > Go.
-- Safe to run on live data: adds one optional column, changes no existing rows.
-- Existing "shelf_bin" values stay as the Bin number. Fill Shelf from Locations > Edit.
ALTER TABLE locations ADD COLUMN shelf VARCHAR(80) NULL AFTER rack;
