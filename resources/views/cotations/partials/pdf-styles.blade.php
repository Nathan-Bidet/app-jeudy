@page {
    size: A4 {{ $pdfOrientation ?? 'landscape' }};
    margin: 8mm;
}

* {
    box-sizing: border-box;
}

body {
    font-family: 'DejaVu Sans', sans-serif;
    font-size: 11px;
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
    width: 100%;
}

.page:last-child {
    page-break-after: auto;
}

.section-title {
    margin: 6px 0 4px;
    padding: 4px 8px;
    background: #facc51;
    color: #1a1a1a;
    font-size: 11.5px;
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
    margin-bottom: 12px;
}

.transport-section {
    page-break-inside: avoid;
}

th, td {
    border: 1px solid #d8d8d8;
    padding: 5px 6px;
    text-align: center;
}

thead th {
    background: #f3f3f3;
    font-size: 9.5px;
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

.pdf-header {
    width: 100%;
    margin-bottom: 6px;
    border-bottom: 2px solid #facc51;
    padding-bottom: 5px;
}

.pdf-header-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.pdf-header-table td {
    border: 0;
    padding: 0;
    vertical-align: middle;
}

.pdf-logo-cell {
    width: 34mm;
    text-align: left;
}

.pdf-logo {
    max-width: 30mm;
    max-height: 16mm;
}

.pdf-title-cell {
    text-align: center;
}

.pdf-title {
    margin: 0;
    font-size: 17px;
    line-height: 1.1;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.pdf-subtitle {
    margin-top: 3px;
    font-size: 10px;
    color: #555;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.pdf-meta-cell {
    width: 43mm;
    text-align: right;
    font-size: 8.8px;
    line-height: 1.35;
    color: #555;
}

.pdf-meta-label {
    font-weight: 700;
    color: #1a1a1a;
}

.information-block {
    display: block;
    width: auto;
    max-width: 100%;
    margin: 6px 0 8px;
    padding: 7px 10px;
    border: 1px solid #d8d8d8;
    background: #fffdf5;
    line-height: 1.35;
    page-break-inside: avoid;
}

.information-block p,
.information-block div {
    margin-top: 0;
    margin-bottom: 6px;
}

.information-block p:last-child,
.information-block div:last-child {
    margin-bottom: 0;
}

.page-cereals {
    font-size: 10px;
}

.page-cereals .harvest-section {
    margin-bottom: 5px;
}

.page-cereals table.cereal-grid {
    table-layout: fixed;
    font-size: 9.4px;
    margin-bottom: 7px;
}

.page-cereals table.cereal-grid th,
.page-cereals table.cereal-grid td {
    padding: 3.5px 4px;
    line-height: 1.2;
    text-align: center;
    word-wrap: break-word;
}

.page-cereals table.cereal-grid thead th {
    font-size: 8.4px;
}

.page-cereals table.cereal-grid .cereal-name {
    font-size: 9.2px;
}

.page-cereals table.cereal-grid td.label,
.page-cereals table.cereal-grid th.label {
    text-align: left;
    font-weight: bold;
    color: #555;
    background: #fafafa;
}

.page-cereals table.cereal-grid tr.row-final td {
    font-weight: bold;
}

.page-cereals table.cereal-grid .group-start {
    border-left: 1.5px solid #999;
}

.page-cereals .empty {
    padding: 7px;
    font-size: 9px;
}

.transport-page .grid-table,
.fuel-page .grid-table {
    table-layout: auto;
    font-size: 10px;
}

.transport-page .transport-section {
    page-break-inside: avoid;
    margin-bottom: 9px;
}

.transport-page .grid-table th,
.transport-page .grid-table td,
.fuel-page .grid-table th,
.fuel-page .grid-table td {
    padding: 4px 6px;
}

.fuel-page .columns {
    width: 100%;
    margin-bottom: 4px;
}

.fuel-page .col {
    display: inline-block;
    width: 49%;
    vertical-align: top;
}

.fuel-page .col + .col {
    margin-left: 1.5%;
}

.fuel-page .section-title {
    margin-top: 4px;
    padding: 3px 7px;
}

.fuel-page table.grid-table {
    margin-bottom: 7px;
}
