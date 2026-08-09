import { defineStore } from 'pinia'
import { ref } from 'vue'
import apiService from '@/shared/services/apiService'

export const useQaStore = defineStore('qa', () => {
  const reviews = ref<any[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const fetchReviews = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await apiService.get('/qa-reviews')
      reviews.value = response.data?.data || response.data || []
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Failed to load QA reviews'
    } finally {
      loading.value = false
    }
  }

  return { reviews, loading, error, fetchReviews }
})
