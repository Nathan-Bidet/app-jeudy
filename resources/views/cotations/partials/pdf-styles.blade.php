@page {
    size: a4 landscape;
    margin: 6mm;
}

* {
    box-sizing: border-box;
}

body {
    font-family: 'DejaVu Sans', sans-serif;
    font-size: 10px;
    color: #1a1a1a;
}

h1 {
    margin: 0 0 2px;
    font-size: 16px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.meta {
    margin: 0 0 6px;
    font-size: 9px;
    color: #555;
}

.page {
    page-break-after: always;
}

.page:last-child {
    page-break-after: auto;
}

.section-title {
    margin: 6px 0 3px;
    padding: 3px 7px;
    background: #facc51;
    color: #1a1a1a;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    text-align: center;
    vertical-align: middle;
}

.columns {
    width: 100%;
}

.col {
    display: inline-block;
    width: 49%;
    vertical-align: top;
}

.col + .col {
    margin-left: 1.5%;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 4px;
}

table.grid-table {
    margin-bottom: 10px;
}

.transport-section {
    page-break-inside: avoid;
}

th, td {
    border: 1px solid #d8d8d8;
    padding: 4px 6px;
    text-align: center;
}

thead th {
    background: #f3f3f3;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

td.label, th.label {
    text-align: left;
    font-weight: bold;
}

.empty {
    padding: 6px;
    color: #888;
    font-style: italic;
    border: 1px solid #d8d8d8;
}

.no-data {
    padding: 10px;
    color: #888;
    font-style: italic;
}

/* Page céréales uniquement : mise en forme compacte pour tout faire tenir sur une page. */
.page-cereals {
    font-size: 8.5px;
}

.page-cereals .meta {
    margin-bottom: 4px;
}

.page-cereals .harvest-section {
    page-break-inside: avoid;
    margin-bottom: 3px;
}

.page-cereals .section-title {
    margin: 2px 0 1px;
    padding: 2px 6px;
    font-size: 9.5px;
}

.page-cereals table.cereal-grid th,
.page-cereals table.cereal-grid td {
    text-align: center;
}

.page-cereals table.cereal-grid td.label,
.page-cereals table.cereal-grid th.label {
    text-align: left;
    font-weight: normal;
    color: #555;
    background: #fafafa;
}

.page-cereals table.cereal-grid tr.row-final td {
    font-weight: bold;
}

.page-cereals table.cereal-grid .group-start {
    border-left: 1.5px solid #999;
}

.page-cereals table {
    margin-bottom: 1px;
    font-size: 8px;
}

.page-cereals th,
.page-cereals td {
    padding: 1px 4px;
    line-height: 1.15;
}

.page-cereals thead th {
    font-size: 7.5px;
}

.page-cereals .empty {
    padding: 3px 5px;
    font-size: 8px;
}

/* Paliers de densité : appliqués automatiquement selon le nombre de
   céréales pour que tout tienne sur une seule page (cf. exportPdf()). */
.page-cereals.density-1 {
    font-size: 7.5px;
}

.page-cereals.density-1 .section-title {
    margin: 1px 0;
    padding: 1.5px 5px;
    font-size: 8.5px;
}

.page-cereals.density-1 table {
    font-size: 7px;
}

.page-cereals.density-1 th,
.page-cereals.density-1 td {
    padding: 0.5px 3px;
    line-height: 1.05;
}

.page-cereals.density-1 thead th {
    font-size: 6.5px;
}

.page-cereals.density-2 {
    font-size: 6.5px;
}

.page-cereals.density-2 .section-title {
    margin: 0.5px 0;
    padding: 1px 4px;
    font-size: 7.5px;
}

.page-cereals.density-2 table {
    font-size: 6px;
}

.page-cereals.density-2 th,
.page-cereals.density-2 td {
    padding: 0.5px 2px;
    line-height: 1;
}

.page-cereals.density-2 thead th {
    font-size: 5.5px;
}
