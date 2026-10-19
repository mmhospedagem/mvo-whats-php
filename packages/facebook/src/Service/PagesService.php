<?php
declare(strict_types=1);
namespace MVO\Facebook\Service;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\PagesBootstrapReader;
use MVO\Facebook\Storage\SelectionStore;
use MVO\Messenger\Auth\WebSession;

/** Page discovery uses the authenticated launchpoint, without changing identity. */
final class PagesService
{
    public function __construct(private readonly WebSession $session,private readonly ?SelectionStore $selections=null){}
    /** @return array{items:list<array<string,mixed>>,page_info:array{end_cursor:null,has_next_page:null},exhaustive:bool} */
    public function list():array
    {
        try{$response=$this->session->request('GET','https://www.facebook.com/pages/?category=your_pages');}
        catch(\Throwable){throw new FacebookException('facebook_transport_failed','The Facebook Pages request did not complete','not_confirmed',true);}
        if(in_array($response->status,[301,302,303,307,308,401],true))throw new FacebookException('facebook_authentication_required','Facebook requires authentication or verification before listing Pages');
        if($response->status===429)throw new FacebookException('facebook_rate_limited','Facebook temporarily limited Page discovery','not_confirmed',true);
        if($response->status!==200)throw new FacebookException('facebook_http_failed','Facebook did not return the Pages launchpoint','not_confirmed',$response->status>=500);
        $result=(new PagesBootstrapReader())->read($response->body,$this->session->accountId());
        $this->selections?->remember('page',$result['items']);
        return $result;
    }
}
