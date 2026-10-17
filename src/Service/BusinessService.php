<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\Protocol\{BinaryNode,Jid,SchemaCodec};

/** Business profile mutations require a WhatsApp Business account. */
final class BusinessService
{
    private ?\Closure $coverUploader;
    /** @param callable(mixed):array<string,mixed>|null $coverUploader */
    public function __construct(private readonly ServiceTransport $transport,private readonly ?CatalogService $catalogService=null,?callable $coverUploader=null) { $this->coverUploader=$coverUploader===null?null:\Closure::fromCallable($coverUploader); }
    public function catalog():CatalogService { return $this->catalogService??throw new \LogicException('Business catalogue is not configured'); }
    /** @return array<string,mixed> */
    public function getCategories(string $query,string $locale='pt_BR',bool $v2=true):array { return $this->catalog()->getBusinessCategories($query,$locale,$v2); }
    /** @return array<string,mixed> */
    public function getCatalog(?string $jid=null,int $limit=10,?string $cursor=null):array { return $this->catalog()->getCatalog($jid,$limit,$cursor); }
    /** @return array<string,mixed> */
    public function getCollections(?string $jid=null,int $limit=10,?string $cursor=null):array { return $this->catalog()->getCollections($jid,$limit,$cursor); }
    /** @param array<string,mixed> $product
     * @return array<string,mixed> */
    public function productCreate(array $product):array { return $this->catalog()->productCreate($product); }
    /** @param array<string,mixed> $update
     * @return array<string,mixed> */
    public function productUpdate(string $id,array $update):array { return $this->catalog()->productUpdate($id,$update); }
    /** @param list<string> $ids
     * @return array<string,mixed> */
    public function productDelete(array $ids):array { return $this->catalog()->productDelete($ids); }
    /** Uploads a local path, explicit bytes array, or open stream before binding the returned PPS metadata. */
    public function updateCoverPhoto(mixed $source):string {
        $uploaded=($this->coverUploader??throw new \LogicException('Business cover uploader is not configured'))($source);
        foreach(['id','timestamp','token']as$key)if(!is_string($uploaded[$key]??null)||$uploaded[$key]==='')throw new \UnexpectedValueException('Business cover upload missing '.$key);
        $this->bindCoverPhoto($uploaded['id'],$uploaded['timestamp'],$uploaded['token']);return $uploaded['id'];
    }
    /** Parsed fields and raw node preserve future profile fields.
     * @param list<string> $jids
     * @return list<array<string,mixed>> */
    public function getBusinessProfile(array $jids): array
    {
        if ($jids===[]) return []; $jids=array_map(static fn(string $jid):string=>ServiceTransport::user(Jid::recipient($jid)->canonical()->bare()),$jids);$r=$this->transport->iq('business.getProfile','get','s.whatsapp.net','w:biz',[new BinaryNode('business_profile',['v'=>'116'],ServiceTransport::users($jids,'profile'))]); $out=[];
        foreach(ServiceTransport::children($r->child('business_profile'),'profile') as $p) {
            if (!isset($p->attrs['jid'])) continue;
            $row=['jid'=>$p->attrs['jid'],'raw'=>$p]; if(isset($p->attrs['tag'])) $row['tag']=$p->attrs['tag'];
            foreach(ServiceTransport::children($p) as $c) {
                $text=ServiceTransport::text($c);
                if ($text !== null) {
                    if ($c->tag==='website') $row['websites'][]=['url'=>$text];
                    elseif (in_array($c->tag,['latitude','longitude'],true) && is_numeric($text)) $row[$c->tag]=(float)$text;
                    else $row[$c->tag]=$text;
                }
                if($c->tag==='categories') $row['categories']=array_map(static fn(BinaryNode $n):array=>['id'=>$n->attrs['id'] ?? '','name'=>ServiceTransport::text($n) ?? ''],ServiceTransport::children($c,'category'));
                elseif($c->tag==='business_hours') {
                    $configs=[]; foreach(ServiceTransport::children($c,'business_hours_config') as $n) { $config=['dayOfWeek'=>$n->attrs['day_of_week'] ?? '','mode'=>$n->attrs['mode'] ?? ''];foreach(['open_time'=>'openTime','close_time'=>'closeTime'] as $a=>$k) if(isset($n->attrs[$a])) $config[$k]=(int)$n->attrs[$a];$configs[]=$config; }
                    $row['businessHours']=['timezone'=>$c->attrs['timezone'] ?? null,'config'=>$configs];
                } elseif($c->tag==='profile_options') { $options=[];foreach(ServiceTransport::children($c) as $n) if(is_string($n->content)) $options[$n->tag]=$n->content;$row['profileOptions']=$options; }
                elseif($c->tag==='cover_photo') { $row['coverPhoto']=$c->attrs;foreach(ServiceTransport::children($c) as $part)if(is_string($part->content))$row['coverPhoto'][$part->tag]=$part->content; }
            } $out[]=$row;
        } return $out;
    }
    /**
     * @param array<string,mixed> $input */
    public function editBusinessProfile(array $input): void
    {
        foreach($input as$key=>$value)if(!in_array($key,['address','description','email','latitude','longitude','websites','categories','businessHours'],true)||$value===null)throw new \InvalidArgumentException('Unknown/null Business profile field');
        foreach(['address'=>1024,'description'=>65536,'email'=>320]as$key=>$max)if(isset($input[$key])&&(!is_string($input[$key])||strlen($input[$key])>$max||preg_match('//u',$input[$key])!==1||str_contains($input[$key],"\0")))throw new \InvalidArgumentException('Invalid Business '.$key);
        if(isset($input['email'])&&$input['email']!==''&&filter_var($input['email'],FILTER_VALIDATE_EMAIL)===false)throw new \InvalidArgumentException('Invalid Business email');
        if(isset($input['websites'])){
            if(!is_array($input['websites'])||!array_is_list($input['websites'])||count($input['websites'])>2)throw new \InvalidArgumentException('At most two websites');
            foreach($input['websites']as$website){$url=is_array($website)?($website['url']??null):$website;if(!is_string($url)||strlen($url)>2048||filter_var($url,FILTER_VALIDATE_URL)===false||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null)throw new \InvalidArgumentException('Invalid Business website');}
        }
        if(isset($input['categories'])){
            if(!is_array($input['categories'])||!array_is_list($input['categories'])||count($input['categories'])>16)throw new \InvalidArgumentException('Invalid Business categories');
            foreach($input['categories']as$category){$id=is_array($category)?($category['id']??null):$category;if(!is_string($id)||$id===''||strlen($id)>128||preg_match('/[\x00-\x20\x7f]/',$id))throw new \InvalidArgumentException('Invalid Business category ID');}
        }
        if(isset($input['businessHours'])){
            $hours=$input['businessHours'];if(!is_array($hours)||!is_array($hours['config']??null)||!array_is_list($hours['config'])||count($hours['config'])>32)throw new \InvalidArgumentException('Invalid Business hours');
            if(isset($hours['timezone'])){if(!is_string($hours['timezone']))throw new \InvalidArgumentException('Invalid Business timezone');try{new \DateTimeZone($hours['timezone']);}catch(\Exception){throw new \InvalidArgumentException('Invalid Business timezone');}}
            foreach($hours['config']as$config){if(!is_array($config)||!is_string($config['dayOfWeek']??null)||!is_string($config['mode']??null))throw new \InvalidArgumentException('Invalid Business hours');if($config['mode']==='specific_hours'&&(!array_key_exists('openTime',$config)||!array_key_exists('closeTime',$config)))throw new \InvalidArgumentException('Specific Business hours require opening and closing minutes');}
        }
        $nodes=[]; foreach(['address','description','email'] as $key) if (isset($input[$key])) { if (!is_string($input[$key])) throw new \InvalidArgumentException('Business field must be string'); $nodes[]=new BinaryNode($key,[],$input[$key]); }
        foreach(['latitude','longitude'] as $key) if (isset($input[$key])) { if (!is_numeric($input[$key]) || !is_finite((float)$input[$key]) || abs((float)$input[$key])>($key==='latitude' ? 90 : 180)) throw new \InvalidArgumentException('Invalid coordinates'); $nodes[]=new BinaryNode($key,[],(string)$input[$key]); }
        if (isset($input['websites'])) { if (!is_array($input['websites']) || count($input['websites'])>2) throw new \InvalidArgumentException('At most two websites'); if ($input['websites']===[]) $nodes[]=new BinaryNode('website'); foreach($input['websites'] as $website) $nodes[]=new BinaryNode('website',[],is_string($website) ? $website : (string)$website['url']); }
        if (isset($input['categories'])) { $categories=[]; foreach($input['categories'] as $cat) $categories[]=new BinaryNode('category',['id'=>is_string($cat) ? $cat : (string)$cat['id']]); $nodes[]=new BinaryNode('categories',[],$categories); }
        if (isset($input['businessHours'])) { $hours=$input['businessHours']; $configs=[]; foreach($hours['config'] as $c) { $mode=$c['mode']; if (!in_array($mode,['specific_hours','open_24h','appointment_only'],true) || !in_array($c['dayOfWeek'],['mon','tue','wed','thu','fri','sat','sun'],true)) throw new \InvalidArgumentException('Invalid business hours'); $a=['day_of_week'=>$c['dayOfWeek'],'mode'=>$mode]; if ($mode==='specific_hours') foreach(['openTime'=>'open_time','closeTime'=>'close_time'] as $key=>$attr) if (isset($c[$key])) { if (!is_int($c[$key]) || $c[$key]<0 || $c[$key]>1440) throw new \InvalidArgumentException('Invalid time in minutes'); $a[$attr]=(string)$c[$key]; } $configs[]=new BinaryNode('business_hours_config',$a); } $nodes[]=new BinaryNode('business_hours',isset($hours['timezone']) ? ['timezone'=>$hours['timezone']] : [],$configs); }
        if ($nodes === []) throw new \InvalidArgumentException('Empty business profile edit'); $this->transport->iq('business.editProfile','set','s.whatsapp.net','w:biz',[new BinaryNode('business_profile',['v'=>'3','mutation_type'=>'delta'],$nodes)]);
    }
    /**
     * @return array<string,mixed>|null */
    public function getVerifiedName(string $jid): ?array
    {
        $n=$this->transport->iq('business.getVerifiedName','get','s.whatsapp.net','w:biz',[new BinaryNode('verified_name',['jid'=>ServiceTransport::user(Jid::recipient($jid)->canonical()->bare())])])->child('verified_name'); return self::verified($n);
    }
    /**
     * @param list<string> $jids
     * @return list<array<string,mixed>> */
    public function getVerifiedNames(array $jids): array
    {
        if ($jids===[]) return []; $jids=array_map(static fn(string $jid):string=>ServiceTransport::user(Jid::recipient($jid)->canonical()->bare()),$jids);$out=[]; foreach($this->transport->usync([new BinaryNode('business',[],[new BinaryNode('verified_name')])],ServiceTransport::users($jids,'user')) as $u) $out[]=['jid'=>$u->attrs['jid'] ?? '','verifiedName'=>self::verified($u->child('business')?->child('verified_name'))]; return $out;
    }
    /** Bind an already uploaded plaintext PPS cover (id/ts/meta_hmac). */
    public function bindCoverPhoto(string $id,string $timestamp,string $token): void { foreach([$id,$timestamp,$token] as $v) if ($v==='') throw new \InvalidArgumentException('Cover upload response missing fields'); $this->cover(['op'=>'update','id'=>$id,'ts'=>$timestamp,'token'=>$token]); }
    public function deleteCoverPhoto(string $id): void { if ($id==='') throw new \InvalidArgumentException('Cover id required'); $this->cover(['op'=>'delete','id'=>$id]); }
    /**
     * @param array<string,string> $attrs */
    private function cover(array $attrs): void { $this->transport->iq('business.coverPhoto','set','s.whatsapp.net','w:biz',[new BinaryNode('business_profile',['v'=>'3','mutation_type'=>'delta'],[new BinaryNode('cover_photo',$attrs)])]); }
    /**
     * @return array<string,mixed>|null */
    private static function verified(?BinaryNode $node): ?array
    {
        if ($node===null) return null; $bytes=ServiceTransport::text($node); $details=[];$cert=[];
        if($bytes!==null) { $cert=SchemaCodec::decode('VerifiedNameCertificate',$bytes);if(isset($cert['details'])) $details=SchemaCodec::decode('VerifiedNameCertificate.Details',$cert['details']); }
        $out=['name'=>$details['verifiedName'] ?? null,'level'=>$node->attrs['verified_level'] ?? null,'serial'=>$node->attrs['serial'] ?? (isset($details['serial']) ? (string)$details['serial'] : null),'isApi'=>($details['issuer'] ?? '')==='ent:wa','isSmb'=>($details['issuer'] ?? '')==='smb:wa','certificate'=>$cert,'raw'=>$node];
        if(isset($node->attrs['actual_actors'],$node->attrs['host_storage'],$node->attrs['privacy_mode_ts'])) $out['privacyMode']=['actualActors'=>(int)$node->attrs['actual_actors'],'hostStorage'=>(int)$node->attrs['host_storage'],'privacyModeTs'=>(int)$node->attrs['privacy_mode_ts']];
        return $out;
    }
}
