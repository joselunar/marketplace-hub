<?php

namespace App\Models;

use CodeIgniter\Model;

class MarketplaceListingEventModel extends Model
{
    public const ACTION_PUBLISH      = 'publish';
    public const ACTION_SYNC         = 'sync';
    public const ACTION_UPDATE_PRICE = 'update_price';
    public const ACTION_UPDATE_STOCK = 'update_stock';
    public const ACTION_PAUSE        = 'pause';
    public const ACTION_ACTIVATE     = 'activate';

    public const ACTION_LABELS = [
        self::ACTION_PUBLISH      => 'Publicação',
        self::ACTION_SYNC         => 'Sincronização',
        self::ACTION_UPDATE_PRICE => 'Atualização de preço',
        self::ACTION_UPDATE_STOCK => 'Atualização de estoque',
        self::ACTION_PAUSE        => 'Pausa',
        self::ACTION_ACTIVATE     => 'Reativação',
    ];

    public const STATUS_SUCCESS = 'success';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR   = 'error';

    protected $table         = 'marketplace_listing_events';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $updatedField  = '';
    protected $allowedFields = ['marketplace_listing_id', 'action', 'status', 'message', 'old_values', 'new_values', 'user_id'];

    public function forListing(int $listingId, int $limit = 30): array
    {
        return $this->select('marketplace_listing_events.*, users.name AS user_name')
            ->join('users', 'users.id = marketplace_listing_events.user_id', 'left')
            ->where('marketplace_listing_events.marketplace_listing_id', $listingId)
            ->orderBy('marketplace_listing_events.id', 'DESC')
            ->findAll($limit);
    }
}
