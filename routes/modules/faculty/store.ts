import { defineStore } from 'pinia'
import { ref } from 'vue'
import apiService from '@/shared/services/apiService'

export const useFacultyStore = defineStore('faculty', () => {
  const documents = ref<any[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const fetchDocuments = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await apiService.get('/documents')
      documents.value = response.data?.data || response.data || []
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Failed to load documents'
    } finally {
      loading.value = false
    }
  }

  return { documents, loading, error, fetchDocuments }
})
