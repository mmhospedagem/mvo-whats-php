/* Thread-safe polling bridge: libdatachannel never invokes PHP from a worker thread. */
#include <rtc/rtc.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#ifdef _WIN32
#include <windows.h>
#define API __declspec(dllexport)
typedef CRITICAL_SECTION mutex_t;
static void mutex_init(mutex_t *m) { InitializeCriticalSection(m); }
static void lock(mutex_t *m) { EnterCriticalSection(m); }
static void unlock(mutex_t *m) { LeaveCriticalSection(m); }
static void mutex_destroy(mutex_t *m) { DeleteCriticalSection(m); }
static void pause_one(void) { Sleep(1); }
#else
#include <pthread.h>
#include <time.h>
#define API __attribute__((visibility("default")))
typedef pthread_mutex_t mutex_t;
static void mutex_init(mutex_t *m) { pthread_mutex_init(m, NULL); }
static void lock(mutex_t *m) { pthread_mutex_lock(m); }
static void unlock(mutex_t *m) { pthread_mutex_unlock(m); }
static void mutex_destroy(mutex_t *m) { pthread_mutex_destroy(m); }
static void pause_one(void) { struct timespec t={0,1000000}; nanosleep(&t,NULL); }
#endif
typedef struct packet { struct packet *next; int size; char data[]; } packet;
typedef struct peer {
    struct peer *next; uintptr_t handle; int refs;
    mutex_t mutex; int pc, dc, channels[32], count, state, gathering, error, closing;
    packet *head, *tail; unsigned int queued, bytes;
} peer;
static mutex_t registry_mutex;
static peer *registry=NULL;
static uintptr_t serial=1;
#ifdef _WIN32
static INIT_ONCE once=INIT_ONCE_STATIC_INIT;
static BOOL CALLBACK initialize(PINIT_ONCE a,PVOID b,PVOID *c) { (void)a;(void)b;(void)c;mutex_init(&registry_mutex);rtcInitLogger(RTC_LOG_NONE,NULL);rtcSetThreadPoolSize(4);return TRUE; }
static void init(void) { InitOnceExecuteOnce(&once,initialize,NULL,NULL); }
#else
static pthread_once_t once=PTHREAD_ONCE_INIT;
static void initialize(void) { mutex_init(&registry_mutex);rtcInitLogger(RTC_LOG_NONE,NULL);rtcSetThreadPoolSize(4); }
static void init(void) { pthread_once(&once,initialize); }
#endif
static peer *acquire(void *opaque) {
    uintptr_t h=(uintptr_t)opaque;peer *p;lock(&registry_mutex);
    for(p=registry;p;p=p->next)if(p->handle==h){++p->refs;break;}
    unlock(&registry_mutex);return p;
}
static void release(peer *p) { lock(&registry_mutex);--p->refs;unlock(&registry_mutex); }
static void state_changed(int id,rtcState state,void *opaque) { (void)id;peer *p=acquire(opaque);if(!p)return;lock(&p->mutex);p->state=state;unlock(&p->mutex);release(p); }
static void gathering_changed(int id,rtcGatheringState state,void *opaque) { (void)id;peer *p=acquire(opaque);if(!p)return;lock(&p->mutex);p->gathering=state;unlock(&p->mutex);release(p); }
static void error_changed(int id,const char *error,void *opaque) { (void)id;(void)error;peer *p=acquire(opaque);if(!p)return;lock(&p->mutex);p->error=-6;unlock(&p->mutex);release(p); }
static void message_received(int id,const char *data,int size,void *opaque) {
    (void)id;peer *p=acquire(opaque);if(!p)return;
    lock(&p->mutex);
    if(!p->closing && size>0 && size<=65535) {
        if(p->queued>=512 || p->bytes+(unsigned int)size>2097152)p->error=-7;
        else {packet *m=malloc(sizeof(packet)+(size_t)size);if(!m)p->error=-8;else{m->next=NULL;m->size=size;memcpy(m->data,data,(size_t)size);if(p->tail)p->tail->next=m;else p->head=m;p->tail=m;++p->queued;p->bytes+=(unsigned int)size;}}
    }
    unlock(&p->mutex);release(p);
}
static void incoming_channel(int pc,int dc,void *opaque) {
    (void)pc;peer *p=acquire(opaque);if(!p){rtcDeleteDataChannel(dc);return;}
    lock(&p->mutex);int keep=!p->closing && p->count<32;if(keep){p->channels[p->count++]=dc;if(p->dc<0)p->dc=dc;}else p->error=-9;unlock(&p->mutex);
    if(keep){rtcSetUserPointer(dc,opaque);rtcSetMessageCallback(dc,message_received);rtcSetErrorCallback(dc,error_changed);}else rtcDeleteDataChannel(dc);release(p);
}
API int mvo_rtc_abi(void) { return 1; }
API void *mvo_rtc_create(const char *bind_address,int min_port,int max_port) {
    init();peer *p=calloc(1,sizeof(peer));if(!p)return NULL;mutex_init(&p->mutex);p->pc=-1;p->dc=-1;
    lock(&registry_mutex);p->handle=serial++;p->next=registry;registry=p;unlock(&registry_mutex);
    rtcConfiguration config={0};config.bindAddress=bind_address;config.disableAutoNegotiation=true;config.portRangeBegin=(uint16_t)min_port;config.portRangeEnd=(uint16_t)max_port;config.mtu=1280;config.maxMessageSize=65535;
    p->pc=rtcCreatePeerConnection(&config);if(p->pc<0){lock(&p->mutex);p->error=p->pc;unlock(&p->mutex);return (void *)p->handle;}
    void *opaque=(void *)p->handle;rtcSetUserPointer(p->pc,opaque);rtcSetStateChangeCallback(p->pc,state_changed);rtcSetGatheringStateChangeCallback(p->pc,gathering_changed);rtcSetDataChannelCallback(p->pc,incoming_channel);
    return opaque;
}
API int mvo_rtc_offer(void *opaque) {
    peer *p=acquire(opaque);if(!p)return -1;if(p->pc<0){int e=p->error;release(p);return e;}
    lock(&p->mutex);int allowed=!p->closing && p->dc<0 && p->count<32;unlock(&p->mutex);if(!allowed){release(p);return -1;}
    rtcDataChannelInit config={0};config.reliability.unordered=true;
    int dc=rtcCreateDataChannelEx(p->pc,"wa-web-call",&config);if(dc<0){release(p);return dc;}
    lock(&p->mutex);allowed=!p->closing && p->count<32;if(allowed){p->dc=dc;p->channels[p->count++]=dc;}unlock(&p->mutex);
    if(!allowed){rtcDeleteDataChannel(dc);release(p);return -1;}
    rtcSetUserPointer(dc,opaque);rtcSetMessageCallback(dc,message_received);rtcSetErrorCallback(dc,error_changed);
    int result=rtcSetLocalDescription(p->pc,"offer");release(p);return result;
}
API int mvo_rtc_remote(void *opaque,const char *sdp,const char *type) {
    peer *p=acquire(opaque);if(!p)return -1;int r=rtcSetRemoteDescription(p->pc,sdp,type);
    if(r==0 && strcmp(type,"offer")==0)r=rtcSetLocalDescription(p->pc,"answer");release(p);return r;
}
API int mvo_rtc_description(void *opaque,char *buffer,int size) { peer *p=acquire(opaque);if(!p)return -1;int r=rtcGetLocalDescription(p->pc,buffer,size);release(p);return r; }
API int mvo_rtc_state(void *opaque) { peer *p=acquire(opaque);if(!p)return RTC_CLOSED;lock(&p->mutex);int r=p->error<0?RTC_FAILED:p->state;unlock(&p->mutex);release(p);return r; }
API int mvo_rtc_gathered(void *opaque) { peer *p=acquire(opaque);if(!p)return 0;lock(&p->mutex);int r=p->gathering==RTC_GATHERING_COMPLETE;unlock(&p->mutex);release(p);return r; }
API int mvo_rtc_open(void *opaque) { peer *p=acquire(opaque);if(!p)return 0;lock(&p->mutex);int dc=p->dc, error=p->error;unlock(&p->mutex);int r=error==0 && dc>=0 && rtcIsOpen(dc);release(p);return r; }
API int mvo_rtc_send(void *opaque,const char *bytes,int size) {
    if(size<1 || size>65535)return -1;peer *p=acquire(opaque);if(!p)return -1;lock(&p->mutex);int dc=p->dc;unlock(&p->mutex);
    int r=dc>=0 && rtcIsOpen(dc) && rtcGetBufferedAmount(dc)<1048576?rtcSendMessage(dc,bytes,size):-3;release(p);return r;
}
API int mvo_rtc_receive(void *opaque,char *buffer,int capacity) {
    peer *p=acquire(opaque);if(!p)return -1;lock(&p->mutex);int r=p->error;packet *m=p->head;
    if(r==0 && m){if(capacity<m->size)r=-4;else{memcpy(buffer,m->data,(size_t)m->size);r=m->size;p->head=m->next;if(!p->head)p->tail=NULL;--p->queued;p->bytes-=(unsigned int)m->size;free(m);}}
    unlock(&p->mutex);release(p);return r;
}
API void mvo_rtc_close(void *opaque) {
    init();peer *p=NULL;lock(&registry_mutex);peer **link=&registry;while(*link){if((*link)->handle==(uintptr_t)opaque){p=*link;*link=p->next;break;}link=&(*link)->next;}unlock(&registry_mutex);if(!p)return;
    lock(&p->mutex);p->closing=1;unlock(&p->mutex);
    if(p->pc>=0){rtcSetDataChannelCallback(p->pc,NULL);rtcSetStateChangeCallback(p->pc,NULL);rtcSetGatheringStateChangeCallback(p->pc,NULL);rtcClosePeerConnection(p->pc);}
    for(;;){lock(&registry_mutex);int refs=p->refs;unlock(&registry_mutex);if(refs==0)break;pause_one();}
    for(int i=0;i<p->count;i++){rtcSetMessageCallback(p->channels[i],NULL);rtcSetErrorCallback(p->channels[i],NULL);rtcDeleteDataChannel(p->channels[i]);}
    if(p->pc>=0)rtcDeletePeerConnection(p->pc);
    packet *m=p->head;while(m){packet *next=m->next;free(m);m=next;}mutex_destroy(&p->mutex);free(p);
}
