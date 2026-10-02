<?php

namespace App\Services\Marketplaces\Contracts;

use App\Exceptions\BusinessRuleException;

class UnsupportedMarketplaceOperation extends BusinessRuleException
{
    public static function for(MarketplaceAdapter $adapter, string $capability): self
    {
        $label = MarketplaceAdapter::CAPABILITY_LABELS[$capability] ?? $capability;

        return new self(sprintf('A integração com %s não oferece "%s".', $adapter->name(), $label));
    }
}
