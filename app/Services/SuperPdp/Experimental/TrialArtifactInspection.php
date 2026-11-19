<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Experimental;

use App\Services\SuperPdp\CiiDocumentChecks;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/** Bounded local inspection. Does not attest API provenance, archive readiness or permission. */
final class TrialArtifactInspection
{
    public static function inspect(string $xml, string $expectedSha256, ?array $report = null): array
    {
        if ($xml === '' || strlen($xml) > 5242880 || str_contains($xml, "\0")
            || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $xml)
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256)
            || ! hash_equals($expectedSha256, hash('sha256', $xml))) {
            throw new RuntimeException('CII bounds or expected digest mismatch.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (! $document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
                throw new RuntimeException('CII XML refused.');
            }
            $x = new DOMXPath($document);
            $x->registerNamespace('rsm', CiiDocumentChecks::NS);
            $x->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
            $agreement = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement';
            foreach (['Seller' => ['Burger Queen', '315143296_18172', '000000002'],
                'Buyer' => ['Tricatel', '315143296_18171', '000000001']] as $role => [$name, $address, $identity]) {
                $party = $agreement.'/ram:'.$role.'TradeParty';
                foreach (['/ram:Name' => $name, '/ram:URIUniversalCommunication/ram:URIID' => $address,
                    '/ram:URIUniversalCommunication/ram:URIID/@schemeID' => '0225',
                    '/ram:SpecifiedLegalOrganization/ram:ID' => $identity,
                    '/ram:SpecifiedLegalOrganization/ram:ID/@schemeID' => '0002'] as $suffix => $expected) {
                    $nodes = $x->query($party.$suffix);
                    if ($nodes->length !== 1 || $nodes->item(0)->textContent !== $expected) {
                        throw new RuntimeException('Wrong direction, identity or electronic address for the approved trial.');
                    }
                }
            }
            if ($report !== null) {
                (new CiiDocumentChecks)->assertReport($report, $xml);
            }

            return ['status' => 'local_artifact_checks_passed', 'cii_sha256' => $expectedSha256,
                'validation_report_consistent' => $report !== null, 'live_validation_proven' => false,
                'routing_api_verified' => false, 'ready_for_emission' => false, 'deposit_performed' => false];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
