import { useState } from "react";

import NavBar from "../components/NavBar";
import ChatList from "../components/ChatList";
import ChatWindow from "../components/ChatWind";
import TaskPanel from "../components/TaskPanel";
import "../styles/dashboard.css";

export default function Dashboard() {
  const [view, setView] = useState("chats");

  return (
    <div className="dashboard">
      <NavBar />

      <div className="dashboard-body">
        {(view === "chats" || window.innerWidth > 768) && (
          <ChatList onOpenChat={() => setView("chat")} />
        )}

        {(view === "chat" || window.innerWidth > 768) && (
          <ChatWindow
            onBack={() => setView("chats")}
            onOpenTasks={() => setView("tasks")}
          />
        )}

        {(view === "tasks" || window.innerWidth > 768) && (
          <TaskPanel onBack={() => setView("chat")} />
        )}
      </div>
    </div>
  );
}
