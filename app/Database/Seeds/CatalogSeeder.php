<?php

namespace App\Database\Seeds;

use App\Models\CategoryModel;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Models\SupplierModel;
use App\Models\SupplierOfferModel;
use App\Services\Suppliers\SupplierSelectionService;
use App\Support\Input;
use CodeIgniter\Database\Seeder;

/**
 * Produtos fictícios com ofertas variadas para exercitar o ranking de fornecedores:
 * preço menor x estoque baixo, prazo, produto sem fornecedor, sem estoque e oferta sem vínculo.
 */
class CatalogSeeder extends Seeder
{
    private const PRODUCTS = [
        [
            'sku' => 'FONE-LP40', 'ean' => '7890000000017', 'name' => 'Fone Bluetooth Lenovo LP40', 'brand' => 'Lenovo',
            'category' => 'audio', 'weight' => '0.150', 'height' => '10', 'width' => '8', 'length' => '4', 'active' => 1,
            'offers' => [
                'Distribuidora Alpha' => ['sku' => 'ABC123', 'cost' => '49.00', 'stock' => 0, 'shipping' => '8.00', 'days' => null],
                'Fornecedor Beta'     => ['sku' => 'LNV991', 'cost' => '53.00', 'stock' => 28, 'shipping' => '9.50', 'days' => null],
                'Fornecedor Gamma'    => ['sku' => 'LP40C', 'cost' => '58.00', 'stock' => 105, 'shipping' => '7.00', 'days' => null],
            ],
        ],
        [
            'sku' => 'SMW-FIT-PRO', 'ean' => '7890000000024', 'name' => 'Smartwatch Fit Pro (Produto A)', 'brand' => 'Genérica',
            'category' => 'wearables', 'weight' => '0.200', 'height' => '12', 'width' => '9', 'length' => '6', 'active' => 1,
            'offers' => [
                'Distribuidora Alpha' => ['sku' => 'ALP-SW01', 'cost' => '100.00', 'stock' => 20, 'shipping' => '10.00', 'days' => 2],
                'Fornecedor Beta'     => ['sku' => 'BT-FITPRO', 'cost' => '95.00', 'stock' => 1, 'shipping' => '12.00', 'days' => 1],
                'Fornecedor Gamma'    => ['sku' => 'GM-778', 'cost' => '105.00', 'stock' => 150, 'shipping' => '9.00', 'days' => 3],
            ],
        ],
        [
            'sku' => 'CX-SOM-MINI', 'ean' => '7890000000031', 'name' => 'Caixa de Som Portátil Mini 10W', 'brand' => 'SoundMax',
            'category' => 'audio', 'weight' => '0.450', 'height' => '15', 'width' => '10', 'length' => '10', 'active' => 1,
            'offers' => [
                'Distribuidora Alpha' => ['sku' => 'ALP-CX10', 'cost' => '89.90', 'stock' => 12, 'shipping' => '14.00', 'days' => null, 'blocked_reason' => 'Lote com defeito reportado por clientes'],
                'Fornecedor Gamma'    => ['sku' => 'GM-CX-10W', 'cost' => '92.00', 'stock' => 40, 'shipping' => '11.00', 'days' => 6],
            ],
        ],
        [
            'sku' => 'CARR-USBC-20W', 'ean' => '7890000000048', 'name' => 'Carregador USB-C 20W Turbo', 'brand' => 'VoltPlus',
            'category' => 'acessorios-para-celular', 'weight' => '0.080', 'height' => '6', 'width' => '5', 'length' => '4', 'active' => 1,
            'offers' => [
                'Fornecedor Beta' => ['sku' => 'BT-CHG20', 'cost' => '25.00', 'stock' => 300, 'shipping' => '5.00', 'days' => null],
            ],
        ],
        [
            'sku' => 'CABO-HDMI-2M', 'ean' => '7890000000055', 'name' => 'Cabo HDMI 2.0 4K 2 metros', 'brand' => 'LinkPro',
            'category' => 'cabos-e-adaptadores', 'weight' => '0.120', 'height' => '3', 'width' => '15', 'length' => '15', 'active' => 1,
            'offers' => [
                'Distribuidora Alpha' => ['sku' => 'ALP-HDMI2', 'cost' => '12.50', 'stock' => 0, 'shipping' => '4.00', 'days' => null],
                'Fornecedor Beta'     => ['sku' => 'BT-HDMI-2M', 'cost' => '13.00', 'stock' => 0, 'shipping' => '4.50', 'days' => null],
            ],
        ],
        [
            'sku' => 'MOUSE-SF-01', 'ean' => '7890000000062', 'name' => 'Mouse Sem Fio Ergonômico 1600 DPI', 'brand' => 'ClickOne',
            'category' => 'perifericos', 'weight' => '0.100', 'height' => '5', 'width' => '7', 'length' => '12', 'active' => 1,
            'offers' => [],
        ],
        [
            'sku' => 'TEC-MEC-RGB', 'ean' => '7890000000079', 'name' => 'Teclado Mecânico RGB Switch Blue', 'brand' => 'KeyForce',
            'category' => 'perifericos', 'weight' => '1.100', 'height' => '5', 'width' => '15', 'length' => '45', 'active' => 1, 'safety_stock' => 4,
            'offers' => [
                'Distribuidora Alpha' => ['sku' => 'ALP-TKRGB', 'cost' => '175.00', 'stock' => 5, 'shipping' => '18.00', 'days' => null],
                'Fornecedor Gamma'    => ['sku' => 'GM-TEC-BLUE', 'cost' => '180.00', 'stock' => 8, 'shipping' => '15.00', 'days' => 10],
            ],
        ],
        [
            'sku' => 'SUP-CEL-CARRO', 'ean' => '7890000000086', 'name' => 'Suporte Veicular Magnético para Celular', 'brand' => 'VoltPlus',
            'category' => 'acessorios-para-celular', 'weight' => '0.090', 'height' => '6', 'width' => '6', 'length' => '8', 'active' => 0,
            'offers' => [
                'Fornecedor Beta' => ['sku' => 'BT-SUPMAG', 'cost' => '9.90', 'stock' => 50, 'shipping' => '4.00', 'days' => null],
            ],
        ],
    ];

    /**
     * Oferta importada que ainda não corresponde a nenhum produto do catálogo.
     */
    private const UNLINKED_OFFERS = [
        'Distribuidora Alpha' => ['sku' => 'ALP-PB10K', 'name' => 'Power Bank 10000mAh Slim', 'ean' => '7890000000093', 'cost' => '59.90', 'stock' => 35, 'shipping' => '7.00'],
    ];

    public function run(): void
    {
        $products   = new ProductModel();
        $images     = new ProductImageModel();
        $offers     = new SupplierOfferModel();
        $categories = array_column((new CategoryModel())->select('id, slug')->findAll(), 'id', 'slug');
        $suppliers  = array_column((new SupplierModel())->select('id, name')->findAll(), 'id', 'name');
        $now        = date('Y-m-d H:i:s');

        foreach (self::PRODUCTS as $data) {
            if ($products->where('sku', $data['sku'])->countAllResults() > 0) {
                continue;
            }

            $products->skipValidation(true)->insert([
                'sku'         => $data['sku'],
                'ean'         => $data['ean'],
                'name'        => $data['name'],
                'slug'        => Input::slug($data['name']),
                'description' => "Produto de demonstração: {$data['name']}.",
                'brand'       => $data['brand'],
                'category_id' => $categories[$data['category']] ?? null,
                'weight'      => $data['weight'],
                'height'      => $data['height'],
                'width'       => $data['width'],
                'length'      => $data['length'],
                'safety_stock' => $data['safety_stock'] ?? null,
                'active'      => $data['active'],
            ]);
            $productId = (int) $products->getInsertID();

            $images->insert([
                'product_id' => $productId,
                'url'        => 'https://placehold.co/800x800/png?text=' . rawurlencode($data['sku']),
                'position'   => 0,
            ]);

            foreach ($data['offers'] as $supplierName => $offer) {
                if (! isset($suppliers[$supplierName])) {
                    continue;
                }
                $offers->skipValidation(true)->insert([
                    'supplier_id'           => $suppliers[$supplierName],
                    'product_id'            => $productId,
                    'supplier_sku'          => $offer['sku'],
                    'supplier_product_name' => $data['name'],
                    'ean'                   => $data['ean'],
                    'cost_price'            => $offer['cost'],
                    'stock_quantity'        => $offer['stock'],
                    'shipping_cost'         => $offer['shipping'],
                    'shipping_days'         => $offer['days'],
                    'active'                => 1,
                    'blocked'               => isset($offer['blocked_reason']) ? 1 : 0,
                    'blocked_reason'        => $offer['blocked_reason'] ?? null,
                    'last_sync_at'          => $now,
                ]);
            }
        }

        foreach (self::UNLINKED_OFFERS as $supplierName => $offer) {
            if (! isset($suppliers[$supplierName]) || $offers->findBySupplierSku($suppliers[$supplierName], $offer['sku']) !== null) {
                continue;
            }
            $offers->skipValidation(true)->insert([
                'supplier_id'           => $suppliers[$supplierName],
                'product_id'            => null,
                'supplier_sku'          => $offer['sku'],
                'supplier_product_name' => $offer['name'],
                'ean'                   => $offer['ean'],
                'cost_price'            => $offer['cost'],
                'stock_quantity'        => $offer['stock'],
                'shipping_cost'         => $offer['shipping'],
                'active'                => 1,
                'last_sync_at'          => $now,
            ]);
        }

        service('supplierSelection')->selectAll(SupplierSelectionService::TRIGGER_SEED);
    }
}
