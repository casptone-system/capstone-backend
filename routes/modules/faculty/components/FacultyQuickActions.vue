<template>
  <ion-card class="shadow-md">
    <ion-card-header>
      <ion-card-title>Quick Actions</ion-card-title>
    </ion-card-header>
    <ion-card-content>
      <div class="grid grid-cols-2 gap-2">
        <ion-button expand="block" fill="outline" :router-link="'/documents'">
          <template #start><ion-icon :icon="documentTextOutline"></ion-icon></template>
          Documents
        </ion-button>
        <ion-button expand="block" fill="outline" :router-link="'/upload'">
          <template #start><ion-icon :icon="cloudUploadOutline"></ion-icon></template>
          Upload
        </ion-button>
        <ion-button expand="block" fill="outline" :router-link="'/reports'">
          <template #start><ion-icon :icon="barChartOutline"></ion-icon></template>
          Reports
        </ion-button>
        <ion-button expand="block" fill="outline" :router-link="'/join-team'">
          <template #start><ion-icon :icon="peopleOutline"></ion-icon></template>
          Join Team
        </ion-button>
      </div>
    </ion-card-content>
  </ion-card>
</template>

<script setup lang="ts">
import {
  IonCard,
  IonCardContent,
  IonCardHeader,
  IonCardTitle,
  IonButton,
  IonIcon,
} from '@ionic/vue'
import {
  documentTextOutline,
  cloudUploadOutline,
  barChartOutline,
  peopleOutline,
} from 'ionicons/icons'
</script>

<script lang="ts">
export default {
  name: 'FacultyQuickActions',
}
</script>
