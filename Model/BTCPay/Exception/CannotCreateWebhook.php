<?php
/*
 * @author Wouter Samaey <wouter.samaey@storefront.agency>
 * @license MIT
 */

declare(strict_types=1);


namespace Storefront\BTCPay\Model\BTCPay\Exception;


class CannotCreateWebhook extends \RuntimeException
{
    public function __construct(array $data, int $status, string $body)
    {
        $message = 'Cannot create webhook using data: ' . \json_encode($data, JSON_THROW_ON_ERROR) . '. Response was status ' . $status . ': ' . $body;
        parent::__construct($message, 0, null);
    }

}
