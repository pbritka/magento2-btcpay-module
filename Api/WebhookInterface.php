<?php
/*
 * @author Wouter Samaey <wouter.samaey@storefront.agency>
 * @license MIT
 */

declare(strict_types=1);

namespace Storefront\BTCPay\Api;

interface WebhookInterface {

    /**
     * Process
     * @return bool
     */
    public function process(): bool;


}
