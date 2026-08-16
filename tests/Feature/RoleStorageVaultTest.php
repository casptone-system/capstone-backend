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

    public function test_user_can_rename_and_favorite_role_storage_file(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Faculty',
            'last_name' => 'Two',
            'email' => 'faculty.storage.rename@example.com',
        ]);

        $user->assignRole('Faculty');
        $this->actingAs($user, 'sanctum');

        $folderResponse = $this->postJson('/api/role-storage/folders', [
            'name' => 'Teaching',
            'role' => 'faculty',
        ]);

        $folderId = $folderResponse->json('data.id');

        $file = UploadedFile::fake()->create('draft.pdf', 120, 'application/pdf');
        $uploadResponse = $this->postJson('/api/role-storage/folders/' . $folderId . '/upload', [
            'file' => $file,
        ]);

        $fileId = $uploadResponse->json('data.id');

        $renameResponse = $this->patchJson('/api/role-storage/files/' . $fileId, [
            'name' => 'ADAMS Research Evidence.pdf',
        ]);

        $renameResponse->assertStatus(200)
            ->assertJsonPath('data.name', 'ADAMS Research Evidence.pdf');

        $favoriteResponse = $this->postJson('/api/role-storage/files/' . $fileId . '/favorite');

        $favoriteResponse->assertStatus(200)
            ->assertJsonPath('data.is_favorite', true);
    }
}
