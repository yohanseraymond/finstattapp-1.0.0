<?php

namespace App\Http\Requests;

use App\Rules\PPVMinMax;
use Illuminate\Foundation\Http\FormRequest;
use App\Rules\Sluggable;

class SaveNewMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            'price' => [new PPVMinMax('message')]
        ];

        if(getSetting('websockets.driver') === 'pusher'){
            $rules['message'] = 'max:800';
        }

        return $rules;
    }
}
