import { defineStore } from 'pinia'
import { ref } from 'vue'
import apiService from '@/shared/services/apiService'

export const useVpaaStore = defineStore('vpaa', () => {
  const reports = ref<any[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const fetchReports = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await apiService.get('/reports')
      reports.value = response.data?.data || response.data || []
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Failed to load reports'
    } finally {
      loading.value = false
    }
  }

  return { reports, loading, error, fetchReports }
})
