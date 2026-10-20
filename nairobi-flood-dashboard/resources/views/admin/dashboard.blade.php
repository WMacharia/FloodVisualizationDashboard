<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | FloodViz</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#0f172a] text-[#F5F5F5] min-h-screen font-sans antialiased">
    <nav class="border-b border-slate-800 bg-[#1e293b] px-6 py-4 flex justify-between items-center">
        <h1 class="text-xl font-bold tracking-tight text-white">FloodViz Management Console</h1>
        
        <div class="flex items-center space-x-6">
            <a href="/" class="text-sky-400 hover:text-sky-300 text-sm font-semibold transition-colors">
                View Live Flood Map
            </a>
            <form action="{{ route('admin.logout') }}" method="POST">
                @csrf
                <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold rounded-lg transition-colors">
                    Sign Out
                </button>
            </form>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <!-- Stats Row -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="p-6 bg-[#1e293b] rounded-xl border border-slate-700/60 shadow-lg">
                <span class="text-xs uppercase font-semibold text-slate-400 tracking-wider">Registered Platform Users</span>
                <p class="text-3xl font-extrabold text-sky-400 mt-2">{{ $totalUsers ?? 0 }}</p>
            </div>
            <div class="p-6 bg-[#1e293b] rounded-xl border border-slate-700/60 shadow-lg">
                <span class="text-xs uppercase font-semibold text-slate-400 tracking-wider">30-Day Platform Traffic</span>
                <p class="text-3xl font-extrabold text-emerald-400 mt-2">{{ $traffic ?? 0 }} <span class="text-sm font-normal text-slate-400">visits</span></p>
            </div>
        </div>

        <!-- Users Table -->
        <div class="bg-[#1e293b] rounded-xl border border-slate-700/60 shadow-lg overflow-hidden">
            <div class="p-6 border-b border-slate-800">
                <h2 class="text-lg font-bold text-white">User Accounts</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-300">
                    <thead class="bg-[#0f172a] uppercase text-xs text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="px-6 py-3">User</th>
                            <th class="px-6 py-3">Email</th>
                            <th class="px-6 py-3">Role</th>
                            <th class="px-6 py-3">Joined Date</th>
                            <th class="px-6 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @if(isset($users) && $users->count() > 0)
                            @foreach ($users as $user)
                                <tr class="hover:bg-slate-800/50 transition-colors">
                                    <td class="px-6 py-4 font-medium text-white flex items-center space-x-3">
                                        @if($user->avatar)
                                            <img src="{{ $user->avatar }}" class="w-7 h-7 rounded-full" alt="avatar">
                                        @else
                                            <div class="w-7 h-7 rounded-full bg-slate-700 flex items-center justify-center text-xs font-bold">
                                                {{ strtoupper(substr($user->name, 0, 1)) }}
                                            </div>
                                        @endif
                                        <span>{{ $user->name }}</span>
                                    </td>
                                    <td class="px-6 py-4">{{ $user->email }}</td>
                                    <td class="px-6 py-4">
                                        <span class="px-2.5 py-1 text-xs rounded-full font-semibold {{ $user->role === 'admin' ? 'bg-sky-500/20 text-sky-400' : 'bg-slate-700 text-slate-300' }}">
                                            {{ strtoupper($user->role) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">{{ $user->created_at->format('M d, Y') }}</td>
                                    <td class="px-6 py-4 text-right">
                                        @if ($user->role !== 'admin')
                                            <form action="{{ route('admin.users.destroy', $user) }}" method="POST" onsubmit="return confirm('Delete this user account?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-400 hover:text-red-300 font-semibold text-xs transition-colors">
                                                    Delete
                                                </button>
                                            </form>
                                        @else
                                            <span class="text-xs text-slate-500 italic">Protected</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @else
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-slate-500">No users found.</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>