<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Real MIME is inspected by the 'mimetypes' rule (not the client
            // filename or extension) and the image is re-validated as an image.
            'image' => [
                'required', 'image', 'mimetypes:'.implode(',', $this->allowedMimes()),
                'max:'.config('pos.uploads.product_image_max_kb'),
                'dimensions:max_width=4096,max_height=4096',
            ],
        ];
    }

    private function allowedMimes(): array
    {
        $map = ['image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/webp' => 'webp'];

        return array_values(array_intersect_key($map, array_flip(config('pos.uploads.allowed_image_mimes'))));
    }
}
