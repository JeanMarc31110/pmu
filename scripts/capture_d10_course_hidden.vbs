Set args = WScript.Arguments
If args.Count < 3 Then
    WScript.Quit 1
End If

dateCourse = args.Item(0)
reunion = args.Item(1)
course = args.Item(2)

Set shell = CreateObject("WScript.Shell")
cmd = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""C:\xampp\htdocs\pmu\scripts\capture_d10_course.ps1"" -Date """ & dateCourse & """ -Reunion """ & reunion & """ -Course """ & course & """"
shell.Run cmd, 0, False
