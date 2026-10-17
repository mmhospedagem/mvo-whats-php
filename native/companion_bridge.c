/* MVO native build adapter for the published libmlow-wasm Companion ABI.
 * The four oc_* adapters follow native/mlow_wasm_wrapper.c at revision
 * 0f50232f5b3667da3c3d8208cdd5ef4c8d48f390.
 * Copyright (c) 2026 openclaw. MIT; see THIRD_PARTY_NOTICES.md.
 * Native Companion and opus_mlow sources retain their own original notices.
 */
#include <opus.h>
#include <opus_smpl_hook.h>
#include "companion_decoder.h"

#if defined(_WIN32)
#define MVO_COMPANION_API __declspec(dllexport)
#else
#define MVO_COMPANION_API __attribute__((visibility("default")))
#endif

MVO_COMPANION_API MlowCompanion *oc_companion_create(const unsigned char *model, int bytes, int *error) {
    if (model == 0 || bytes <= 0) { if (error != 0) *error = COMPANION_BAD_ARG; return 0; }
    return companion_create(model, (size_t)bytes, error);
}
MVO_COMPANION_API void oc_companion_destroy(MlowCompanion *companion) { companion_destroy(companion); }
MVO_COMPANION_API void oc_companion_reset(MlowCompanion *companion) { if (companion != 0) companion_reset(companion); }
MVO_COMPANION_API int oc_decoder_set_companion(OpusDecoder *decoder, MlowCompanion *companion) {
    if (companion == 0) return opus_decoder_ctl(decoder, OPUS_SET_SMPL_FRAME_HOOK_REQUEST, (const opus_smpl_frame_hook *)0);
    const opus_smpl_frame_hook hook = { companion_decoder_hook, companion };
    return opus_decoder_ctl(decoder, OPUS_SET_SMPL_FRAME_HOOK_REQUEST, &hook);
}
