{{-- Email informatif : une journée d'heures vient d'être soumise au groupe. --}}
@component('mail::message')
# Heures à valider

**{{ $details['user_label'] }}** a enregistré ses heures du **{{ $details['work_date'] }}**.

@if($details['is_not_worked'])
Journée déclarée **non travaillée**.

@if(filled($details['description']))
**Motif**

{{ $details['description'] }}
@endif
@else
@component('mail::table')
| | |
| :--- | :--- |
@if($details['schedule'])
| **Horaires** | {{ $details['schedule'] }} |
@endif
| **Total travaillé** | {{ $details['total'] }} |
@if($details['overtime'])
| **Heures supplémentaires** | {{ $details['overtime'] }} |
@endif
@if(count($details['extras']) > 0)
| **Cases cochées** | {{ implode(', ', $details['extras']) }} |
@endif
@if($details['group_name'])
| **Groupe** | {{ $details['group_name'] }} |
@endif
@endcomponent

@if(filled($details['description']))
**Travaux réalisés**

{{ $details['description'] }}
@endif
@endif

Cette journée est **en attente de validation** par les valideurs du groupe.

@component('mail::subcopy')
Cet email est informatif : les heures se valident ou se refusent depuis l'application. Vous le recevez parce que votre adresse est configurée sur ce groupe de validation.
@endcomponent
@endcomponent
