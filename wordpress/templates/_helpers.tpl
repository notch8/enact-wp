{{/* Service name of the nested mysql subchart -- shared by wordpress and mysql templates. */}}
{{- define "wordpress.mysqlHost" -}}
{{ .Release.Name }}-mysql
{{- end -}}
