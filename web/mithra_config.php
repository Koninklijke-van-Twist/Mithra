<?php

/**
 * Constants
 */
const MITHRA_SCAN_DB_FILENAME = 'mithra_scans.sqlite3';
const MITHRA_BC_ENTITY = 'Scanposten';
const MITHRA_BC_SELECT_FIELDS = 'Entry_No,KVT_User_Name_Scanner,Scan_Process,Starting_Date,Starting_Time';

const MITHRA_WH_BC_ENTITY = 'Magazijnposten';
const MITHRA_WH_BC_SELECT_FIELDS = 'Entry_No,Entry_Type,Quantity,Whse_Document_No,Registering_Date,User_ID';
const MITHRA_SYNC_CHUNK_DAYS = 7;

/** Stop backfill na dit aantal opeenvolgende maanden zonder scanregels. */
const MITHRA_BACKFILL_EMPTY_MONTHS_STOP = 6;

/** Maximale terugwerkende backfill in dagen (fallback-grens). */
const MITHRA_BACKFILL_MAX_DAYS = 3650;

const MITHRA_HEATMAP_DAYS = 28;
const MITHRA_HEATMAP_COLS = 7;
const MITHRA_HEATMAP_ROWS = 4;

/** Kaart-PNG: moet overeenkomen met CSS .heat-cell / .heatmap in index.php */
const MITHRA_HEATMAP_CELL_PX = 14;
const MITHRA_HEATMAP_CELL_GAP = 2;
const MITHRA_HEATMAP_CELL_RADIUS = 2;

/** Aantal weekrijen in het heatmap-grid naast het gebruikersmodal. */
const MITHRA_MODAL_HEATMAP_ROWS = 50;

/** Bovengrens voor blauwe intensiteit; hogere waarden worden geel weergegeven. */
const MITHRA_HEATMAP_INTENSITY_MAX = 100;

/** Op/ boven limiet: PNG loopt van oranje (#FF8800) naar geel (#FFFF00) tot dit veelvoud. */
const MITHRA_HEATMAP_OVER_LIMIT_MULTIPLIER = 5;

const MITHRA_ODATA_TTL = 300;
