<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RoleStorageVaultTest extends TestCase
{
    public function test_user_can_create_folder_and_upload_file_in_role_storage(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Faculty',
            'last_name' => 'User',
            'email' => 'faculty.storage@example.com',
        ]);

        $user->assignRole('Faculty');

        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/role-storage/folders', [
            'name' => 'My Folder',
            'role' => 'faculty',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'My Folder');

        $folderId = $response->json('data.id');

        $file = UploadedFile::fake()->create('notes.pdf', 120, 'application/pdf');

        $uploadResponse = $this->postJson('/api/role-storage/folders/' . $folderId . '/upload', [
            'file' => $file,
        ]);

        $uploadResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'notes.pdf');

        $fileId = $uploadResponse->json('data.id');

        $downloadResponse = $this->get('/api/role-storage/files/' . $fileId . '/download');

        $downloadResponse->assertStatus(200)
            ->assertHeader('content-disposition', 'inline; filename="notes.pdf"');
    }
}
