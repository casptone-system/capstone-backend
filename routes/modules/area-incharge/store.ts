import { defineStore } from 'pinia'
import { ref } from 'vue'
import apiService from '@/shared/services/apiService'

export const useAreaInChargeStore = defineStore('area-incharge', () => {
  const areas = ref<any[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const fetchAreas = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await apiService.get('/accreditation-areas')
      areas.value = response.data?.data || response.data || []
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Failed to load areas'
    } finally {
      loading.value = false
    }
  }

  return { areas, loading, error, fetchAreas }
})
