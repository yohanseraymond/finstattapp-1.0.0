<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadAttachamentRequest;
use App\Model\Attachment;
use App\Providers\AttachmentServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Log;
use Pusher\Pusher;


class AttachmentController extends Controller
{

    /**
     * Process the attachment and upload it to the selected storage driver.
     *
     * @param UploadAttachamentRequest $request
     * @param bool $type Dummy param to follow route parameters
     * @param bool $chunkedFile If using chunk uploads, this final chunked file is sent over this request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(UploadAttachamentRequest $request, $type = false, $chunkedFile = false)
    {

        if($chunkedFile){
            $file = $chunkedFile;
        }
        else{
            $file = $request->file('file');
        }

        $type = $request->route('type');

        $fileMimeType = $file->getMimeType();
        try {
            switch ($fileMimeType) {
                case 'video/mp4':
                case 'video/avi':
                case 'video/quicktime':
                case 'video/x-m4v':
                case 'video/mpeg':
                case 'video/wmw':
                case 'video/x-matroska':
                case 'video/x-ms-asf':
                case 'video/x-ms-wmv':
                case 'video/x-ms-wmx':
                case 'video/x-ms-wvx':
                    $directory = 'videos';
                    break;
                case 'audio/mpeg':
                case 'audio/ogg':
                case 'audio/wav':
                    $directory = 'audio';
                    break;
                case 'application/vnd.ms-excel':
                case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                case 'application/pdf':
                    $directory = 'documents';
                    break;
                default:
                    $directory = 'images';
                    break;
            }

            $generateThumbnail = false;
            if ($type == 'post') {
                $directory = 'posts/'.$directory;
                $generateThumbnail = true;
            } elseif ($type == 'message') {
                $directory = 'messenger/'.$directory;
                $generateThumbnail = true;
            } elseif ($type == 'payment-request'){
                $directory = 'payment-request/'.$directory;
            }

            $attachment = AttachmentServiceProvider::createAttachment($file, $directory, $generateThumbnail);

            if($chunkedFile){
                unlink($file->getPathname());
            }

        } catch (\Exception $exception) {
            return response()->json(['success' => false, 'errors' => [$exception->getMessage(), $exception->getTrace()], 'message' => $exception->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'attachmentID' => $attachment->id,
            'path' => Storage::url($attachment->filename),
            'type' => AttachmentServiceProvider::getAttachmentType($attachment->type),
            'thumbnail' => AttachmentServiceProvider::getThumbnailPathForAttachmentByResolution($attachment, 150, 150),
            'coconut_id' => $attachment->coconut_id,
            'has_thumbnail' => $attachment->has_thumbnail
        ]);
    }

    /**
     * Chunk uploadining method
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws UploadMissingFileException
     * @throws \Pion\Laravel\ChunkUpload\Exceptions\UploadFailedException
     */
    public function uploadChunk(Request $request, $type = false){
        $receiver = new FileReceiver("file", $request, HandlerFactory::classFromRequest($request));
        if ($receiver->isUploaded() === false) {
            throw new UploadMissingFileException();
        }
        $save = $receiver->receive();
        // check if the upload has finished (in chunk mode it will send smaller files)
        if ($save->isFinished()) {
            $saveRequest = new UploadAttachamentRequest(['file'=>$save->getFile()]);
            $saveRequest->validate($saveRequest->rules());
            return $this->upload($saveRequest, $type, $save->getFile());
        }
        // we are in chunk mode, lets send the current progress
        $handler = $save->handler();
        return response()->json(['success' => true, 'data' => ['percentage'=>$handler->getPercentageDone()]]);
    }

    /**
     * Removes attachment out of db & out of the storage driver.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeAttachment(Request $request)
    {
        try {
            $attachment = Attachment::where('id', $request->get('attachmentId'))->first();
            if ($attachment != null) {
                AttachmentServiceProvider::removeAttachment($attachment);
                $attachment->delete();
            }
            return response()->json(['success' => true, 'data' => [__('Attachments removed successfully')]]);
        } catch (\Exception $exception) {
            return response()->json(['success' => false, 'errors' => [$exception->getMessage()]]);
        }
    }

    /**
     * Handles coconut webhook
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Pusher\ApiErrorException
     * @throws \Pusher\PusherException
     */
    public static function handleCoconutHook(Request $request){

        Log::channel('coconut')->info(__("New coconut payload available"));
        Log::channel('coconut')->info(json_encode($request->all()));

        $attachmentID = $request->get('attachmentId');
        $attachment = Attachment::where('id', $attachmentID)->first();
        $username = $attachment->user->username;

        if(config('broadcasting.connections.pusher.key')){
            $options = [
                'cluster' => config('broadcasting.connections.pusher.options.cluster'),
                'useTLS' => true,
            ];
            $pusher = new Pusher(
                config('broadcasting.connections.pusher.key'),
                config('broadcasting.connections.pusher.secret'),
                config('broadcasting.connections.pusher.app_id'),
                $options
            );
        }

        if($request->get('event') === 'job.completed'){
            // 2. Delete the temporary attachment that got transcoded
            $storage = Storage::disk(AttachmentServiceProvider::getStorageProviderName($attachment->driver));
            $storage->delete($attachment->filename);

            $attachment->filename = "posts/videos/{$attachmentID}.mp4";
            $attachment->type = "mp4";
            $attachment->has_thumbnail = 1;
            $attachment->save();

            // Notify the UI via a websocket call
            if(config('broadcasting.connections.pusher.key')){
                unset($attachment->user);
                $attachment->setAttribute('success', true);
                $pusher->trigger($username, 'video-processing', $attachment);
            }
        }
        elseif($request->get('event') === 'job.failed' || $request->get('event') === 'output.failed'){
            // Notify the UI via a websocket call
            if(config('broadcasting.connections.pusher.key')){
                $attachment->setAttribute('success', false);
                $pusher->trigger($username, 'video-processing', $attachment);
            }
        }

        return response()->json(['success' => true, 'message' => __("Video updated")], 200);

    }
}

