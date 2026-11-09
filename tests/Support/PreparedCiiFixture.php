<?php

namespace Tests\Support;

use App\Services\SuperPdp\CiiDocumentChecks;
use DOMXPath;
use RuntimeException;

/** Réponse simulée dérivée EN MÉMOIRE de la référence standard, jamais une conversion réelle. */
final class PreparedCiiFixture
{
    public static function xml(array $payload): string
    {
        $x = SandboxCiiChecks::parse(CapturedSandboxCii::xml());
        $values = [
            SandboxCiiChecks::DOCUMENT.'/ram:ID' => $payload['number'],
            SandboxCiiChecks::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString' => str_replace('-', '', $payload['issue_date']),
            SandboxCiiChecks::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString' => str_replace('-', '', $payload['payment_due_date']),
            SandboxCiiChecks::LINE.'/ram:SpecifiedTradeProduct/ram:Name' => $payload['lines'][0]['item_information']['name'],
        ];
        foreach ($payload['notes'] as $note) {
            $values[SandboxCiiChecks::DOCUMENT."/ram:IncludedNote[ram:SubjectCode='".$note['subject_code']."']/ram:Content"] = $note['note'];
        }
        foreach (['seller' => 'Seller', 'buyer' => 'Buyer'] as $key => $role) {
            $party = SandboxCiiChecks::TRADE.'/ram:ApplicableHeaderTradeAgreement/ram:'.$role.'TradeParty';
            $expected = $payload[$key];
            $values += [
                $party.'/ram:Name' => $expected['name'],
                $party.'/ram:GlobalID' => $expected['identifiers'][0]['value'],
                $party.'/ram:SpecifiedLegalOrganization/ram:ID' => $expected['legal_registration_identifier']['value'],
                $party.'/ram:SpecifiedTaxRegistration/ram:ID' => $expected['vat_identifier'],
                $party.'/ram:URIUniversalCommunication/ram:URIID' => $expected['electronic_address']['value'],
                $party.'/ram:URIUniversalCommunication/ram:URIID/@schemeID' => $expected['electronic_address']['scheme'],
            ];
            foreach (['address_line1' => 'LineOne', 'post_code' => 'PostcodeCode', 'city' => 'CityName', 'country_code' => 'CountryID'] as $field => $node) {
                $values[$party.'/ram:PostalTradeAddress/ram:'.$node] = $expected['postal_address'][$field];
            }
        }
        foreach ($values as $path => $value) {
            self::set($x, $path, $value);
        }

        return $x->document->saveXML();
    }

    public static function set(DOMXPath $x, string $path, string $value): void
    {
        $nodes = $x->query($path);
        if ($nodes->length !== 1) {
            throw new RuntimeException('Le modèle CII de test a changé : '.$path);
        }
        $node = $nodes->item(0);
        $node->nodeValue = '';
        if ($node instanceof \DOMAttr) {
            $node->value = $value;
        } else {
            $node->appendChild($x->document->createTextNode($value));
        }
    }

    public static function report(string $xml): array
    {
        return ['data' => [[
            'file_name' => 'invoice.xml', 'file_size' => strlen($xml), 'is_valid' => true,
            'format' => CiiDocumentChecks::NS, 'conformance_level' => 'urn:cen.eu:en16931:2017', 'duration' => 1,
            'subreports' => [['validator' => 'SIMULATION-EN16931-CII', 'checks_count' => 1, 'messages' => [], 'failures' => []]],
        ]]];
    }
}
