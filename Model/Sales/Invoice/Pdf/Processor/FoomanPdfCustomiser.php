<?php

namespace ShoppingFeed\Manager\Model\Sales\Invoice\Pdf\Processor;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\InvoiceInterface;
use ShoppingFeed\Manager\Api\Sales\Invoice\Pdf\ProcessorInterface;

class FoomanPdfCustomiser implements ProcessorInterface
{
    /**
     * @var \Fooman\PdfCore\Model\PdfRendererFactory|null
     */
    private $pdfRendererFactory;

    /**
     * @var \Fooman\PdfCustomiser\Block\InvoiceFactory|null
     */
    private $invoiceDocumentFactory;

    public function __construct()
    {
        try {
            $objectManager = ObjectManager::getInstance();
            $this->pdfRendererFactory = $objectManager->get('\Fooman\PdfCore\Model\PdfRendererFactory');
            $this->invoiceDocumentFactory = $objectManager->get('\Fooman\PdfCustomiser\Block\InvoiceFactory');
        } catch (\Exception $e) {
            $this->pdfRendererFactory = null;
            $this->invoiceDocumentFactory = null;
        }
    }

    public function getCode()
    {
        return 'fooman_pdf_customiser';
    }

    public function getLabel()
    {
        return __('Fooman PDF Customiser');
    }

    public function isAvailable()
    {
        return (
            (null !== $this->pdfRendererFactory)
            && (null !== $this->invoiceDocumentFactory)
        );
    }

    public function getInvoicePdfContent(InvoiceInterface $invoice)
    {
        if ((null === $this->pdfRendererFactory) || (null === $this->invoiceDocumentFactory)) {
            throw new LocalizedException(__('Fooman PDF Customiser module is not installed.'));
        }

        $document = $this->invoiceDocumentFactory->create(
            [
                'data' => [
                    'invoice' => $invoice,
                ],
            ]
        );

        $pdfRenderer = $this->pdfRendererFactory->create();

        try {
            $pdfRenderer->addDocument($document);

            return $pdfRenderer->getPdfAsString();
        } catch (\Exception $e) {
            throw new LocalizedException(__('Failed to generate PDF: %1', $e->getMessage()));
        }
    }
}
