import { defineStore } from 'pinia'
import { ref } from 'vue'
import apiService from '@/shared/services/apiService'

export const useDeanStore = defineStore('dean', () => {
  const submissions = ref<any[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const fetchSubmissions = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await apiService.get('/accreditations')
      submissions.value = response.data?.data || response.data || []
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Failed to load submissions'
    } finally {
      loading.value = false
    }
  }

  return { submissions, loading, error, fetchSubmissions }
})
