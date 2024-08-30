/*
* Post create page
 */
"use strict";
/* global PostCreate, FileUpload, mediaSettings, isAllowedToPost, AiSuggestions, app, trans */

$(function () {
    // Initing button save
    $('.post-create-button').on('click',function () {
        PostCreate.save('create');
    });

    $('.draft-clear-button').on('click',function () {
        PostCreate.clearDraft();
    });
    // Populating draft data, if available
    const draftData = PostCreate.populateDraftData();
    PostCreate.initPostDraft(draftData);
    if(isAllowedToPost){
        // Initiating file manager
        FileUpload.initDropZone('.dropzone','/attachment/upload/post', mediaSettings.use_chunked_uploads);
    }
    if(app.open_ai_enabled) {
        AiSuggestions.initAISuggestions('#dropzone-uploader', 'post');
    }
});


// Saving draft data before unload
window.addEventListener('beforeunload', function (event) {
    // Forcing a dialog when a file is being uploaded/video transcoded
    if(FileUpload.isTranscodingVideo === true || FileUpload.isLoading === true){
        event.returnValue = trans('Are you sure you want to leave?');
    }
    if(!PostCreate.isSavingRedirect){
        PostCreate.saveDraftData();
    }
});
