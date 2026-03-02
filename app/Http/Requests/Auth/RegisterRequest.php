<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'full_name'   => ['required', 'string', 'min:2', 'max:100'],
      'email'       => ['required', 'email', 'unique:users,email'],
      'phone'       => ['required', 'string', 'regex:/^(\+234|0)[789][01]\d{8}$/', 'unique:users,phone'],
      'password'    => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
      'pin'         => ['required', 'digits:4'],
      'device_name' => ['sometimes', 'string', 'max:100'],
    ];
  }

  public function messages(): array
  {
    return [
      'full_name.required' => 'Full name is required.',
      'email.required'     => 'Email address is required.',
      'email.unique'       => 'An account with this email already exists.',
      'phone.required'     => 'Phone number is required.',
      'phone.regex'        => 'Enter a valid Nigerian phone number.',
      'phone.unique'       => 'An account with this phone number already exists.',
      'password.required'  => 'Password is required.',
      'password.confirmed' => 'Passwords do not match.',
      'pin.required'       => 'A 4-digit transaction PIN is required.',
      'pin.digits'         => 'PIN must be exactly 4 digits.',
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
