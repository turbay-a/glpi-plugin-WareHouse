<?php

/**
 * -------------------------------------------------------------------------
 * Assetmove plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Assetmove.
 *
 * Assetmove is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Assetmove is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Assetmove. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetmove\Engine;

use CommonDBTM;
use Document;
use GLPIPDF;
use GlpiPlugin\Assetmove\DocType;
use GlpiPlugin\Assetmove\Document_Item;
use GlpiPlugin\Assetmove\Movement;
use GlpiPlugin\Assetmove\Warehouse;
use Manufacturer;
use User;

/**
 * Builds the data for, and produces a PDF of, the printed "Типова форма
 * N М-11" (накладна-вимога на відпуск/внутрішнє переміщення матеріалів) for
 * a Movement -- requested by the customer after the initial TZ decision to
 * skip printed documents (see PROGRESS.md's decisions record and this
 * feature's own entry).
 *
 * A few fields (header requisites, recipient, price per row) have no
 * reliable single source of truth in this plugin's data model and are
 * editable directly on the print form instead -- saved as an override on
 * the Movement (`print_header`/`print_recipient`) or the row
 * (`Document_Item::price`), pre-filled with the best automatic guess the
 * first time the form is opened. "Basis" reuses the document's own
 * `comment` field rather than a separate column. Passport number and
 * accounting account codes still have no equivalent at all and stay blank
 * for the accountant to fill by hand.
 */
final class PrintM11
{
    private function __construct()
    {
        // Static utility class, never instantiated.
    }

    /**
     * @return array{
     *   movement: Movement,
     *   doctype: DocType,
     *   header_requisites: string,
     *   document_number: string,
     *   document_date: ?string,
     *   basis: string,
     *   sender_label: string,
     *   recipient_label: string,
     *   via_label: string,
     *   handed_over_by: string,
     *   received_by: string,
     *   rows: array<int, array{id:int, order:int, name:string, unit:string, quantity:int, serial:string, price:float, sum:float}>,
     *   total_sum: float,
     * }
     */
    public static function build(Movement $movement): array
    {
        $doctype = new DocType();
        $doctype->getFromDB((int) $movement->fields['plugin_assetmove_doctypes_id']);

        $source_itemtype = $movement->fields['source_itemtype'] ?? null;
        $source_items_id = (int) ($movement->fields['source_items_id'] ?? 0);
        $dest_itemtype    = $movement->fields['dest_itemtype'] ?? null;
        $dest_items_id    = (int) ($movement->fields['dest_items_id'] ?? 0);

        $header_override    = trim((string) ($movement->fields['print_header'] ?? ''));
        $sender_override    = trim((string) ($movement->fields['print_sender'] ?? ''));
        $recipient_override = trim((string) ($movement->fields['print_recipient'] ?? ''));
        $via_override        = trim((string) ($movement->fields['print_via'] ?? ''));

        $rows = self::itemRows($movement);
        $total_sum = array_sum(array_column($rows, 'sum'));

        return [
            'movement'          => $movement,
            'doctype'           => $doctype,
            'header_requisites' => $header_override !== '' ? $header_override : self::headerRequisites($source_itemtype, $source_items_id, $dest_itemtype, $dest_items_id),
            'document_number'   => (string) ($movement->fields['name'] ?? ''),
            'document_date'     => $movement->fields['date_execution']
                ?: $movement->fields['date_mod']
                ?: $movement->fields['date_creation'],
            'basis'             => (string) ($movement->fields['comment'] ?? ''),
            'sender_label'      => $sender_override !== '' ? $sender_override : self::endpointLabel($source_itemtype, $source_items_id),
            'recipient_label'   => $recipient_override !== '' ? $recipient_override : self::endpointLabel($dest_itemtype, $dest_items_id),
            'via_label'         => $via_override,
            'handed_over_by'    => self::signerName($source_itemtype, $source_items_id),
            'received_by'       => self::signerName($dest_itemtype, $dest_items_id),
            'rows'              => $rows,
            'total_sum'         => $total_sum,
        ];
    }

    /**
     * Saves the editable fields posted back from the print form: header/
     * sender/recipient/via overrides and "basis" on the Movement, price per
     * row on each Document_Item. Called from front/movement.print.php on
     * POST, after the caller has already checked the UPDATE right.
     *
     * @param array<string, mixed> $input Raw $_POST.
     */
    public static function save(Movement $movement, array $input): void
    {
        $movement->update([
            'id'              => $movement->getID(),
            'print_header'    => (string) ($input['print_header'] ?? ''),
            'print_sender'    => (string) ($input['print_sender'] ?? ''),
            'print_recipient' => (string) ($input['print_recipient'] ?? ''),
            'print_via'       => (string) ($input['print_via'] ?? ''),
            'comment'         => (string) ($input['comment'] ?? ''),
        ]);

        foreach ((array) ($input['price'] ?? []) as $document_items_id => $price) {
            $document_items_id = (int) $document_items_id;
            $price              = (float) str_replace(',', '.', (string) $price);

            $link = new Document_Item();
            if (
                $link->getFromDB($document_items_id)
                && (int) $link->fields['plugin_assetmove_movements_id'] === $movement->getID()
            ) {
                $link->update(['id' => $document_items_id, 'price' => $price]);
            }
        }
    }

    /**
     * Renders the M-11 as a PDF (table-based markup fed to TCPDF's own HTML
     * renderer -- it has no flexbox/grid support, unlike the on-screen
     * print view, so this is a deliberately simpler layout, not a reuse of
     * movement_print_m11.html.twig) and returns the raw PDF bytes.
     */
    public static function renderPdf(array $data): string
    {
        $pdf = new GLPIPDF(['font' => 'dejavusans'], null, null, false);
        $pdf->SetCreator('GLPI Assetmove');
        $pdf->SetTitle($data['document_number']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML(self::pdfHtml($data), true, false, true, false, '');

        return (string) $pdf->Output('m11.pdf', 'S');
    }

    /**
     * Idempotent by document number: re-saves (overwrites the file of) the
     * same GLPI `Document` on every call for the same Movement instead of
     * piling up a new one on every reprint.
     */
    public static function saveAsDocument(Movement $movement, array $data): Document
    {
        $pdf_bytes = self::renderPdf($data);
        $filename  = 'M11_' . $data['document_number'] . '.pdf';

        // Document::moveDocument() takes the *displayed* filename from the
        // uploaded file's own name minus this prefix (`_prefix_filename`)
        // -- the same trick the real HTML upload flow uses to keep the tmp
        // file name unique on disk while showing a clean name in GLPI.
        $prefix   = uniqid('', true) . '_';
        $tmp_name = $prefix . $filename;
        file_put_contents(GLPI_TMP_DIR . '/' . $tmp_name, $pdf_bytes);

        $document = self::findExistingDocument($movement);

        if ($document !== null) {
            $document->update([
                'id'                => $document->getID(),
                // Present (any value) is what actually triggers
                // Document::prepareInputForUpdate() to process `_filename`
                // at all -- see that method.
                'current_filepath'  => true,
                '_filename'         => [$tmp_name],
                '_prefix_filename'  => [$prefix],
            ]);

            return $document;
        }

        $document = new Document();
        $documents_id = $document->add([
            'name'             => $filename,
            'entities_id'      => (int) $movement->fields['entities_id'],
            '_filename'        => [$tmp_name],
            '_prefix_filename' => [$prefix],
        ]);

        if ($documents_id) {
            (new \Document_Item())->add([
                'documents_id' => $documents_id,
                'itemtype'     => Movement::class,
                'items_id'     => $movement->getID(),
            ]);
            $document->getFromDB($documents_id);
        }

        return $document;
    }

    /**
     * A Document already linked to this Movement whose name matches this
     * document's M-11 filename -- reused (file overwritten) instead of
     * creating a duplicate on every reprint.
     */
    private static function findExistingDocument(Movement $movement): ?Document
    {
        /** @var \DBmysql $DB */
        global $DB;

        $filename = 'M11_' . $movement->fields['name'] . '.pdf';

        $iterator = $DB->request([
            'SELECT'     => ['glpi_documents.id'],
            'FROM'       => 'glpi_documents',
            'INNER JOIN' => [
                'glpi_documents_items' => [
                    'ON' => ['glpi_documents_items' => 'documents_id', 'glpi_documents' => 'id'],
                ],
            ],
            'WHERE' => [
                'glpi_documents_items.itemtype' => Movement::class,
                'glpi_documents_items.items_id' => $movement->getID(),
                'glpi_documents.name'           => $filename,
            ],
            'LIMIT' => 1,
        ]);

        $row = $iterator->current();
        if ($row === null) {
            return null;
        }

        $document = new Document();

        return $document->getFromDB($row['id']) ? $document : null;
    }

    /**
     * Simple `<table>`-only HTML fragment for TCPDF's own (limited) HTML
     * renderer -- no flexbox/grid, unlike the on-screen Twig template.
     */
    private static function pdfHtml(array $data): string
    {
        $rows_html = '';
        foreach ($data['rows'] as $row) {
            $rows_html .= sprintf(
                '<tr><td>%d</td><td>%s</td><td>%s</td><td align="right">%s</td><td align="right">%s</td></tr>',
                $row['order'],
                htmlspecialchars($row['name']),
                htmlspecialchars($row['serial']),
                number_format($row['price'], 2, '.', ' '),
                number_format($row['sum'], 2, '.', ' ')
            );
        }

        $header = htmlspecialchars((string) $data['header_requisites']);
        $basis  = htmlspecialchars((string) $data['basis']);
        $from   = htmlspecialchars((string) $data['sender_label']);
        $to     = htmlspecialchars((string) $data['recipient_label']);
        $via    = htmlspecialchars((string) $data['via_label']);
        $by     = htmlspecialchars((string) $data['handed_over_by']);
        $recv   = htmlspecialchars((string) $data['received_by']);
        $date   = $data['document_date'] ? htmlspecialchars((string) $data['document_date']) : '';

        return <<<HTML
            <h2>НАКЛАДНА-ВИМОГА</h2>
            <p>на відпуск /внутрішнє переміщення/ матеріалів</p>
            <table border="1" cellpadding="3">
                <tr><td><b>{$header}</b></td></tr>
            </table>
            <p>
                Номер документа: {$data['document_number']}<br/>
                Дата складання: {$date}<br/>
                Підстава: {$basis}<br/>
                Від кого: {$from}<br/>
                Кому: {$to}<br/>
                Через кого: {$via}
            </p>
            <table border="1" cellpadding="3">
                <tr>
                    <th>#</th><th>Матеріальні цінності</th><th>Інвентарний номер</th><th>Ціна</th><th>Сума</th>
                </tr>
                {$rows_html}
            </table>
            <p>Всього на суму: {$data['total_sum']}</p>
            <p>
                Здав (відпустив): {$by}<br/>
                Прийняв (одержав): {$recv}
            </p>
            HTML;
    }

    /**
     * The warehouse endpoint's `print_requisites` (organization name/address
     * for the printed header) -- checks source first, then destination,
     * since either side of a Movement can be the Warehouse.
     */
    private static function headerRequisites(?string $source_itemtype, int $source_items_id, ?string $dest_itemtype, int $dest_items_id): string
    {
        $warehouse = self::asWarehouse($source_itemtype, $source_items_id)
            ?? self::asWarehouse($dest_itemtype, $dest_items_id);

        if ($warehouse === null) {
            return '';
        }

        return trim((string) ($warehouse->fields['print_requisites'] ?? '')) !== ''
            ? $warehouse->fields['print_requisites']
            : $warehouse->fields['name'];
    }

    private static function asWarehouse(?string $itemtype, int $items_id): ?Warehouse
    {
        if ($itemtype !== Warehouse::class || $items_id <= 0) {
            return null;
        }

        $warehouse = new Warehouse();

        return $warehouse->getFromDB($items_id) ? $warehouse : null;
    }

    private static function endpointLabel(?string $itemtype, int $items_id): string
    {
        if (empty($itemtype) || $items_id <= 0 || !class_exists($itemtype)) {
            return '';
        }

        $item = new $itemtype();
        if (!$item->getFromDB($items_id)) {
            return '';
        }

        return $item->getFriendlyName() ?: (string) ($item->fields['name'] ?? '');
    }

    /**
     * Who signs "Здав (відпустив)" / "Прийняв (одержав)" for one endpoint:
     * the warehouse keeper (`users_id_manager`) if that side is a Warehouse,
     * or the person themselves if that side is a User.
     */
    private static function signerName(?string $itemtype, int $items_id): string
    {
        if ($itemtype === Warehouse::class && $items_id > 0) {
            $warehouse = new Warehouse();
            if ($warehouse->getFromDB($items_id) && (int) $warehouse->fields['users_id_manager'] > 0) {
                $user = new User();
                if ($user->getFromDB((int) $warehouse->fields['users_id_manager'])) {
                    return $user->getFriendlyName();
                }
            }

            return '';
        }

        if ($itemtype === 'User' && $items_id > 0) {
            $user = new User();

            return $user->getFromDB($items_id) ? $user->getFriendlyName() : '';
        }

        return '';
    }

    /**
     * The item's description on the M-11 line: manufacturer + model (the
     * customer's own instruction -- a hostname is meaningless on a
     * requisition slip an accountant reads) falling back to the asset's own
     * name when it has neither set.
     */
    private static function itemLabel(CommonDBTM $item): string
    {
        $manufacturer_name = '';
        if ($item->isField('manufacturers_id') && (int) $item->fields['manufacturers_id'] > 0) {
            $manufacturer = new Manufacturer();
            if ($manufacturer->getFromDB((int) $item->fields['manufacturers_id'])) {
                $manufacturer_name = (string) $manufacturer->fields['name'];
            }
        }

        $model_name = '';
        $model_class = $item->getModelClass();
        if ($model_class !== null) {
            $model_fk = $model_class::getForeignKeyField();
            if ((int) ($item->fields[$model_fk] ?? 0) > 0) {
                $model = new $model_class();
                if ($model->getFromDB((int) $item->fields[$model_fk])) {
                    $model_name = (string) $model->fields['name'];
                }
            }
        }

        $brand_model = trim($manufacturer_name . ' ' . $model_name);

        return $brand_model !== '' ? $brand_model : (string) ($item->fields['name'] ?? '');
    }

    /**
     * @return array<int, array{id:int, order:int, name:string, unit:string, quantity:int, serial:string, price:float, sum:float}>
     */
    private static function itemRows(Movement $movement): array
    {
        $rows  = [];
        $order = 1;

        foreach ((new Document_Item())->find(['plugin_assetmove_movements_id' => $movement->getID()], ['id ASC']) as $row) {
            if (!class_exists($row['itemtype'])) {
                continue;
            }

            /** @var CommonDBTM $item */
            $item = new $row['itemtype']();
            if (!$item->getFromDB($row['items_id'])) {
                continue;
            }

            $quantity = 1;
            $price    = (float) ($row['price'] ?? 0);

            $rows[] = [
                'id'       => (int) $row['id'],
                'order'    => $order++,
                'name'     => sprintf('%s: %s', $row['itemtype']::getTypeName(1), self::itemLabel($item)),
                'unit'     => __('шт.', 'assetmove'),
                'quantity' => $quantity,
                'serial'   => $item->isField('serial') ? (string) ($item->fields['serial'] ?? '') : '',
                'price'    => $price,
                'sum'      => $quantity * $price,
            ];
        }

        return $rows;
    }
}
