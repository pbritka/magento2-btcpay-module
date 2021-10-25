<?php
/*
 * @author Wouter Samaey <wouter.samaey@storefront.agency>
 * @license MIT
 */

declare(strict_types=1);

namespace Storefront\BTCPay\Model\BTCPay\Exception;

class CannotCreateInvoice extends \RuntimeException
{
    /**
     * @var
     */
    private $httpStatus;

    private $body;

    private $postData;

    public function __construct(int $httpStatus, string $body, array $postData)
    {
        $message = 'Cannot create BTCPay Server Invoice (status ' . $httpStatus . ') with data: ' . \json_encode($postData, JSON_THROW_ON_ERROR) . ' and response body: ' . $body;
        parent::__construct($message, 0, null);
    }
}
