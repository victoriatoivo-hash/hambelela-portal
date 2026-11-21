<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Deployment configuration, never accepted from a browser. Unapproved policy fails closed. */
final class ProofPolicy
{
    public static function configured():array
    {
        // Owner approved on 20 November 2026: recipient signature; representative name/relationship.
        return ['evidence'=>getenv('HO_DRIVER_PROOF_POLICY')?:'signature',
            'representative'=>getenv('HO_DRIVER_PROOF_REPRESENTATIVE')?:'relationship'];
    }
    public static function enabled():bool {return in_array(self::configured()['evidence'],['one','both','signature'],true);}
    public static function validate(array $policy,array $fields,array $evidence):void
    {
        if(!in_array($policy['evidence']??'', ['one','both','signature'],true))throw new \DomainException('Owner approval of the evidence policy is pending. Completion is not enabled.');
        if(!in_array($fields['recipient_type']??'', ['customer','representative'],true))throw new \DomainException('Choose the recipient type.');
        $name=trim((string)($fields['recipient_name']??''));
        if($name===''||mb_strlen($name)>120||preg_match('/[\x00-\x1f]/u',$name))throw new \DomainException('Enter the actual recipient name (up to 120 characters).');
        if(($fields['acknowledged']??'')!=='1')throw new \DomainException('Confirm that the correct items were handed over.');
        if($fields['recipient_type']==='representative'){
            if(($policy['representative']??'')!=='relationship')throw new \DomainException('Representative handover needs dispatch approval. Contact dispatch; do not record them as the customer.');
            $relationship=trim((string)($fields['relationship']??''));
            if($relationship===''||mb_strlen($relationship)>200||preg_match('/[\x00-\x1f]/u',$relationship))throw new \DomainException('Enter the representative’s relationship or authority (up to 200 characters).');
        }
        if($policy['evidence']==='signature'&&!isset($evidence['signature']))throw new \DomainException('The recipient signature is required. A photo alone is not sufficient.');
        if(!$evidence||($policy['evidence']==='both'&&(!isset($evidence['photo'])||!isset($evidence['signature']))))throw new \DomainException($policy['evidence']==='both'?'A photo and recipient signature are required.':'A photo or recipient signature is required.');
    }
}
