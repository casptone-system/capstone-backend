import { defineStore } from 'pinia'
import { ref } from 'vue'
import apiService from '@/shared/services/apiService'

export const useProgramChairStore = defineStore('program-chair', () => {
  const teams = ref<any[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const fetchTeams = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await apiService.get('/programs')
      teams.value = response.data?.data || response.data || []
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Failed to load teams'
    } finally {
      loading.value = false
    }
  }

  return { teams, loading, error, fetchTeams }
})
