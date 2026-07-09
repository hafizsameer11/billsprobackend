<?php

namespace App\Http\Requests\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class UploadFaceVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'face_verification_video' => 'required|file|mimes:mp4,mov,quicktime,webm|max:30720',
        ];
    }

    public function messages(): array
    {
        return [
            'face_verification_video.required' => 'A face verification video is required.',
            'face_verification_video.mimes' => 'The face verification video must be an MP4, MOV, or WebM file.',
            'face_verification_video.max' => 'The face verification video may not be larger than 30MB.',
        ];
    }
}
