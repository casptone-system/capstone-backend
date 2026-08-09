import { defineStore } from 'pinia'
import { ref } from 'vue'
import apiService from '@/shared/services/apiService'

export const useSuperAdminStore = defineStore('super-admin', () => {
  const users = ref<any[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const fetchUsers = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await apiService.get('/users')
      users.value = response.data?.data || response.data || []
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Failed to load users'
    } finally {
      loading.value = false
    }
  }

  return { users, loading, error, fetchUsers }
})
