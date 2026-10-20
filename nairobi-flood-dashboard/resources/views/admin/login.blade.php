<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal Login | FloodViz</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#0f172a] text-[#F5F5F5] min-h-screen flex items-center justify-center font-sans antialiased">
    <div class="w-full max-w-md p-8 bg-[#1e293b] rounded-xl shadow-2xl border border-slate-700">
        <div class="mb-8 text-center">
            <h1 class="text-2xl font-bold tracking-tight text-white">FloodViz Admin Portal</h1>
            <p class="text-xs text-slate-400 mt-2">Sign in with system administrator credentials</p>
        </div>

        @if ($errors->any())
            <div class="mb-6 p-3 bg-red-500/10 border border-red-500/30 rounded text-red-400 text-xs">
                {{ $errors->first() }}
            </div>
        @endif

        <form action="{{ route('admin.login.submit') }}" method="POST" class="space-y-5">
            @csrf
            <div>
                <label for="email" class="block text-xs font-semibold uppercase text-slate-300 mb-2">Email Address</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                    class="w-full px-4 py-2.5 bg-[#0f172a] border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:border-sky-500 transition-colors placeholder-slate-500"
                    placeholder="admin@floodviz.com">
            </div>

            <div>
                <label for="password" class="block text-xs font-semibold uppercase text-slate-300 mb-2">Password</label>
                <input type="password" id="password" name="password" required
                    class="w-full px-4 py-2.5 bg-[#0f172a] border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:border-sky-500 transition-colors placeholder-slate-500"
                    placeholder="••••••••">
            </div>

            <button type="submit"
                class="w-full py-2.5 bg-sky-600 hover:bg-sky-500 text-white font-semibold rounded-lg text-sm transition-colors shadow-lg shadow-sky-600/20">
                Sign In to Dashboard
            </button>
        </form>
    </div>
</body>
</html>