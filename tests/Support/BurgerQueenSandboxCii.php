<?php

namespace Tests\Support;

use Tests\Support\SandboxCiiChecks as Cii;

final class BurgerQueenSandboxCii
{
    /** Simulated XML in memory only, not a provider conversion or emitted document. */
    public static function xml(array $preparation): string
    {
        $payload = $preparation['payload'];
        $x = Cii::parse(CapturedSandboxCii::xml());
        $values = [
            Cii::DOCUMENT.'/ram:ID' => $payload['number'],
            Cii::DOCUMENT.'/ram:IssueDateTime/udt:DateTimeString' => str_replace('-', '', $payload['issue_date']),
            Cii::CONTEXT.'/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID' => $payload['process_control']['business_process_type'],
            Cii::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString' => str_replace('-', '', $payload['payment_due_date']),
            Cii::LINE.'/ram:SpecifiedTradeProduct/ram:Name' => $payload['lines'][0]['item_information']['name'],
        ];
        foreach ($payload['notes'] as $note) {
            $values[Cii::DOCUMENT."/ram:IncludedNote[ram:SubjectCode='".$note['subject_code']."']/ram:Content"] = $note['note'];
        }
        foreach (['seller' => 'Seller', 'buyer' => 'Buyer'] as $key => $role) {
            $path = Cii::TRADE.'/ram:ApplicableHeaderTradeAgreement/ram:'.$role.'TradeParty';
            foreach (iterator_to_array($x->query($path.'/ram:GlobalID')) as $node) {
                $node->parentNode->removeChild($node);
            }
            $party = $payload[$key];
            $values += [
                $path.'/ram:Name' => $party['name'],
                $path.'/ram:SpecifiedLegalOrganization/ram:ID' => $party['legal_registration_identifier']['value'],
                $path.'/ram:SpecifiedLegalOrganization/ram:ID/@schemeID' => $party['legal_registration_identifier']['scheme'],
                $path.'/ram:URIUniversalCommunication/ram:URIID' => $party['electronic_address']['value'],
                $path.'/ram:URIUniversalCommunication/ram:URIID/@schemeID' => $party['electronic_address']['scheme'],
                $path.'/ram:SpecifiedTaxRegistration/ram:ID' => $party['vat_identifier'],
            ];
            foreach (['address_line1' => 'LineOne', 'post_code' => 'PostcodeCode', 'city' => 'CityName', 'country_code' => 'CountryID'] as $field => $node) {
                $values[$path.'/ram:PostalTradeAddress/ram:'.$node] = $party['postal_address'][$field];
            }
        }
        foreach ($values as $path => $value) {
            PreparedCiiFixture::set($x, $path, $value);
        }

        return $x->document->saveXML();
    }
}
