import { computed, ref, type Ref, type ComputedRef } from 'vue'
import { useAuthStore } from '@/stores/authStore'
import { downloadDocument as apiDownloadDocument, getDashboard, getDocuments, getNotifications, getTeam, markAllAsRead, updateDocument, uploadDocument as apiUploadDocument } from '@/lib/api'
import type { AppDocument, DashboardSummary, NotificationMessage, Program, Team } from '@/lib'

export interface FacultyDashboardState {
  team: Ref<Team | null>
  program: Ref<Program | null>
  dashboardSummary: Ref<DashboardSummary>
  documents: Ref<AppDocument[]>
  notifications: Ref<NotificationMessage[]>
  selectedSection: Ref<'dashboard' | 'documents' | 'revisions' | 'join' | 'team' | 'notifications'>
  pipeline: Ref<Array<{ label: string; sub: string; done: boolean; active: boolean; returned?: boolean }>>
  loading: Ref<boolean>
  error: Ref<string | null>
  selectedDocuments: ComputedRef<AppDocument[]>
  pendingRevisions: ComputedRef<AppDocument[]>
  unreadCount: ComputedRef<number>
  dashboardProgram: ComputedRef<string>
  dashboardTeamName: ComputedRef<string>
  dashboardTeamLead: ComputedRef<string>
  loadTeam: () => Promise<void>
  loadDocuments: () => Promise<void>
  loadNotifications: () => Promise<void>
  loadDashboard: () => Promise<void>
  uploadDocument: (file: File, metadata: { title: string; description?: string }) => Promise<boolean>
  updateDocumentMetadata: (id: string, data: { title?: string; description?: string }) => Promise<boolean>
  resubmitDocument: (id: string) => Promise<boolean>
  downloadDocument: (id: string, version?: number) => Promise<void>
  markAllNotificationsRead: () => Promise<void>
  selectSection: (section: 'dashboard' | 'documents' | 'revisions' | 'join' | 'team' | 'notifications') => void
}

export function useFacultyDashboard(): FacultyDashboardState {
  const authStore = useAuthStore()
  const team = ref<Team | null>(null)
  const program = ref<Program | null>(null)
  const dashboardSummary = ref<DashboardSummary>({
    totalPrograms: 0,
    totalAreas: 0,
    totalEvidence: 0,
    totalCycles: 0,
    compliancePercent: 0,
    readinessPercent: 0,
    pendingReviews: 0,
    overdueTasks: 0,
  })
  const documents = ref<AppDocument[]>([])
  const notifications = ref<NotificationMessage[]>([])
  const selectedSection = ref<'dashboard' | 'documents' | 'revisions' | 'join' | 'team' | 'notifications'>('dashboard')
  const pipeline = ref<Array<{ label: string; sub: string; done: boolean; active: boolean; returned?: boolean }>>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const loadTeam = async () => {
    const currentTeamId = authStore.user?.teamId
    if (!currentTeamId) {
      team.value = null
      program.value = authStore.user?.programId
        ? {
            id: authStore.user.programId,
            name: String(authStore.user.role),
            code: '',
            status: 'active'
            }
         : null
      return
    }

    try {
      const response = await getTeam(currentTeamId)
      const data = response.data || response
      team.value = data
      program.value = data.program || null
    } catch (err) {
      console.warn('Failed to load faculty team', err)
      team.value = null
      program.value = null
    }
  }

  const loadDocuments = async () => {
  loading.value = true
  error.value = null

  try {
    const params: Record<string, any> = {}

    if (authStore.user?.programId) {
      params.program_id = authStore.user.programId
    }

    const response = await getDocuments(params)

    documents.value = Array.isArray(response)
      ? response
      : response.data ?? []

    buildPipeline()
  } catch (err: any) {
    error.value =
      err.response?.data?.message ||
      err.message ||
      'Failed to load documents'
  } finally {
    loading.value = false
  }
}

  const loadNotifications = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await getNotifications()
      const payload = response.data || response
      notifications.value = Array.isArray(payload) ? payload : payload.data || []
    } catch (err: any) {
      error.value = err.message || 'Failed to load notifications'
    } finally {
      loading.value = false
    }
  }

  const loadDashboard = async () => {
    loading.value = true
    error.value = null
    try {
      const params: Record<string, any> = {}
      if (authStore.user?.programId) {
        params.program_id = authStore.user.programId
      }
      const response = await getDashboard(params)
      const data = response.data || response
      const summary = data?.data?.summary || data?.summary || {}
      dashboardSummary.value = {
        totalPrograms: summary.totalPrograms || 0,
        totalAreas: summary.totalAreas || 0,
        totalEvidence: summary.totalEvidence || 0,
        totalCycles: summary.totalCycles || 0,
        compliancePercent: summary.compliancePercent || 0,
        readinessPercent: summary.readinessPercent || 0,
        pendingReviews: summary.pendingReviews || 0,
        overdueTasks: summary.overdueTasks || 0,
      }
      buildPipeline()
    } catch (err: any) {
      error.value = err.message || 'Failed to load dashboard metrics'
    } finally {
      loading.value = false
    }
  }

  const buildPipeline = () => {
    const hasDocuments = documents.value.length > 0
    const revisionsCount = documents.value.filter((doc) => doc.status === 'revision').length
    const pendingCount = documents.value.filter((doc) => doc.status === 'pending').length

    pipeline.value = [
      { label: 'Upload Evidence', sub: hasDocuments ? 'Evidence submitted by you' : 'Upload evidence to begin', done: hasDocuments, active: !hasDocuments, returned: false },
      { label: 'Area In-Charge Review', sub: revisionsCount > 0 ? 'Returned for revision' : pendingCount > 0 ? 'Under area review' : 'Waiting for area review', done: hasDocuments && !pendingCount && revisionsCount === 0, active: pendingCount > 0, returned: revisionsCount > 0 },
      { label: 'Program Chair Review', sub: hasDocuments ? 'Pending program chair review' : 'Awaiting prior stage', done: hasDocuments && !pendingCount && revisionsCount === 0, active: false, returned: false },
      { label: 'Dean Review', sub: hasDocuments ? 'Awaiting dean approval' : 'Awaiting prior stage', done: false, active: false, returned: false },
      { label: 'QA Review', sub: hasDocuments ? 'QA compliance check' : 'Awaiting prior stage', done: false, active: false, returned: false },
      { label: 'VPAA Final Review', sub: hasDocuments ? 'Final accreditation review' : 'Awaiting prior stage', done: false, active: false, returned: false },
    ]
  }

  const uploadDocument = async (file: File, metadata: { title: string; description?: string }) => {
    loading.value = true
    error.value = null

    try {
      const payload: Record<string, any> = {
        ...metadata,
        program_id: authStore.user?.programId,
      }
      if (authStore.user?.programId) {
        payload.program_id = authStore.user.programId
      }
      if (authStore.user?.teamId) {
        payload.team_id = authStore.user.teamId
      }
      await apiUploadDocument(file, payload)
      await loadDocuments()
      return true
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Upload failed'
      return false
    } finally {
      loading.value = false
    }
  }

  const updateDocumentMetadata = async (id: string, data: { title?: string; description?: string }) => {
    loading.value = true
    error.value = null
    try {
      await updateDocument(id, data)
      await loadDocuments()
      return true
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Update failed'
      return false
    } finally {
      loading.value = false
    }
  }

  const resubmitDocument = async (id: string) => {
    loading.value = true
    error.value = null
    try {
      await updateDocument(id, { status: 'pending' })
      await loadDocuments()
      return true
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Resubmit failed'
      return false
    } finally {
      loading.value = false
    }
  }

  const downloadDocument = async (id: string, version?: number) => {
    try {
      const blob = await apiDownloadDocument(id, version)
      const fileUrl = window.URL.createObjectURL(new Blob([blob]))
      const anchor = document.createElement('a')
      anchor.href = fileUrl
      anchor.download = `document-${id}.pdf`
      document.body.appendChild(anchor)
      anchor.click()
      document.body.removeChild(anchor)
      window.URL.revokeObjectURL(fileUrl)
    } catch (err: any) {
      console.warn('Download failed', err)
    }
  }

  const markAllNotificationsRead = async () => {
    try {
      await markAllAsRead()
      await loadNotifications()
    } catch (err: any) {
      console.warn('Failed to mark notifications read', err)
    }
  }

  const selectSection = (section: typeof selectedSection.value) => {
    selectedSection.value = section
  }

  const selectedDocuments = computed(() => documents.value)
  const pendingRevisions = computed(() => documents.value.filter((doc) => doc.status === 'revision'))
  const unreadCount = computed(() => notifications.value.filter((note) => !note.read).length)

  const dashboardProgram = computed(() => {
    return team.value?.program?.name || program.value?.name || authStore.user?.role || 'Faculty'
  })

  const dashboardTeamName = computed(() => {
    return team.value?.name || 'No Team Assigned'
  })

  const dashboardTeamLead = computed(() => {
    return team.value?.program?.chair || 'Program Chair'
  })

  return {
    team,
    program,
    dashboardSummary,
    documents,
    notifications,
    selectedSection,
    pipeline,
    loading,
    error,
    selectedDocuments,
    pendingRevisions,
    unreadCount,
    dashboardProgram,
    dashboardTeamName,
    dashboardTeamLead,
    loadTeam,
    loadDocuments,
    loadNotifications,
    loadDashboard,
    uploadDocument,
    updateDocumentMetadata,
    resubmitDocument,
    downloadDocument,
    markAllNotificationsRead,
    selectSection,
  }
}
