<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class UserReport extends Model
{
    public static $typesMap = [
        "I don't like this content",
        "Content is offensive or violates Terms of Service.",
        "Content contains stolen material (DMCA)",
        "Content is spam",
        "Report abuse",
    ];

    public static $statusMap = [
        'received',
        'seen',
        'solved',
        'false',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['from_user_id', 'user_id', 'post_id', 'message_id', 'stream_id', 'type', 'details', 'status'];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
    ];

    /*
     * Relationships
     */

    public function reporterUser()
    {
        return $this->belongsTo('App\User', 'from_user_id');
    }

    public function reportedUser()
    {
        return $this->belongsTo('App\User', 'user_id');
    }

    public function reportedPost()
    {
        return $this->belongsTo('App\Model\Post', 'post_id');
    }

    public function reportedMessage()
    {
        return $this->belongsTo('App\Model\UserMessage', 'message_id');
    }

    public function reportedStream()
    {
        return $this->belongsTo('App\Model\Stream', 'stream_id');
    }

}
