<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class LoginRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'email'       => ['required', 'email'],
      'password'    => ['required', 'string', 'min:6'],
      'device_name' => ['sometimes', 'string', 'max:100'],
    ];
  }

  public function messages(): array
  {
    return [
      'email.required'    => 'Email address is required.',
      'email.email'       => 'Please enter a valid email address.',
      'password.required' => 'Password is required.',
      'password.min'      => 'Password must be at least 6 characters.',
    ];
  }

  protected function failedValidation(Validator $validator): void
  {
    throw new HttpResponseException(response()->json([
      'success' => false,
      'message' => 'Validation failed.',
      'data'    => $validator->errors(),
    ], 422));
  }
}
